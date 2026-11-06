<?php

namespace App\Services;

use App\Repositories\Connection;
use DateTimeImmutable;
use Throwable;

final class GourmetOfflineConversionExporter
{
    private Connection $db;
    private InvoiceService $invoices;

    public function __construct(?Connection $db = null, ?InvoiceService $invoices = null)
    {
        $this->db = $db ?? new Connection();
        $this->invoices = $invoices ?? new InvoiceService();
    }

    /** @return array<int,array<string,mixed>> */
    public function pending(int $ownerId): array
    {
        $this->db->query("SELECT * FROM lead_intake WHERE id_owner=:owner AND source='gourmet_landing' AND created_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY ORDER BY id");
        $this->db->bind(':owner', $ownerId);
        $leads = $this->db->fetchAll();

        $this->db->query('SELECT conversion_action,order_id FROM gourmet_conversion_exports WHERE id_owner=:owner');
        $this->db->bind(':owner', $ownerId);
        $existing = [];
        foreach ($this->db->fetchAll() as $row) {
            $existing[(string)$row->conversion_action . '|' . (string)$row->order_id] = true;
        }

        return self::candidates(
            $leads,
            fn(int $orderId): array => $this->invoices->orderSnapshotForConversion($ownerId, $orderId),
            $existing
        );
    }

    /**
     * Pure candidate builder for fixture-driven tests.
     *
     * @param array<int,object|array<string,mixed>> $leads
     * @param callable(int):array<string,mixed> $orderSnapshot
     * @param array<string,bool> $existing
     * @return array<int,array<string,mixed>>
     */
    public static function candidates(array $leads, callable $orderSnapshot, array $existing = []): array
    {
        $rows = [];
        foreach ($leads as $rawLead) {
            $lead = is_object($rawLead) ? (array)$rawLead : $rawLead;
            if (!empty($lead['created_at'])) {
                try {
                    $createdAt = new DateTimeImmutable((string)$lead['created_at'], GourmetLandingAttributionService::eventTimezone());
                    if ($createdAt < new DateTimeImmutable('-90 days', GourmetLandingAttributionService::eventTimezone())) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
            $payload = json_decode((string)($lead['payload_json'] ?? ''), true);
            if (!is_array($payload) || !empty($payload['is_test']) || strtoupper((string)($lead['status'] ?? '')) === 'TEST') {
                continue;
            }
            $attribution = is_array($payload['attribution'] ?? null) ? $payload['attribution'] : [];
            [$clickType, $clickId] = self::clickId($attribution);
            if ($clickId === '') {
                continue;
            }

            $leadId = (int)($lead['id'] ?? 0);
            $conversionTime = self::normalizeTime((string)($payload['conversion_time'] ?? $lead['created_at'] ?? 'now'));
            $qualified = !empty($payload['qualified_lead']) || strtoupper((string)($lead['availability_status'] ?? '')) === 'QUALIFIED';
            if ($qualified && $leadId > 0) {
                $orderId = 'lead-' . $leadId . '-qualified';
                $action = (string)($_ENV['GOOGLE_ADS_QUALIFIED_LEAD_ACTION'] ?? 'VNV Gourmet - Qualified Lead');
                if (empty($existing[$action . '|' . $orderId])) {
                    $rows[] = self::row($leadId, $action, $orderId, $clickType, $clickId, $conversionTime, 0.0);
                }
            }

            $convertedOrderId = (int)($lead['converted_order_id'] ?? 0);
            if ($convertedOrderId <= 0) {
                continue;
            }
            try {
                $snapshot = $orderSnapshot($convertedOrderId);
            } catch (Throwable $e) {
                error_log('Gourmet conversion order snapshot skipped: ' . $e->getMessage());
                continue;
            }
            $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
            if ((float)($totals['net_paid'] ?? 0) <= 0) {
                continue;
            }
            $order = is_object($snapshot['order'] ?? null) ? (array)$snapshot['order'] : (array)($snapshot['order'] ?? []);
            $orderStatus = strtoupper((string)($order['status'] ?? $order['order_status'] ?? ''));
            if (in_array($orderStatus, ['CANCELLED', 'CANCELED', 'DRAFT', 'VOID', 'VOIDED'], true)) {
                continue;
            }
            $payments = (array)($snapshot['payments'] ?? []);
            $paidAt = null;
            foreach ($payments as $payment) {
                $payment = is_object($payment) ? (array)$payment : (array)$payment;
                if ((float)($payment['net_amount'] ?? 0) <= 0 || empty($payment['paid_at'])) {
                    continue;
                }
                $candidate = (string)$payment['paid_at'];
                if ($paidAt === null || $candidate < $paidAt) {
                    $paidAt = $candidate;
                }
            }
            $orderKey = 'order-' . $convertedOrderId . '-booked';
            $bookedAction = (string)($_ENV['GOOGLE_ADS_BOOKED_EVENT_ACTION'] ?? 'VNV Gourmet - Booked Event');
            if (empty($existing[$bookedAction . '|' . $orderKey])) {
                $rows[] = self::row(
                    $leadId,
                    $bookedAction,
                    $orderKey,
                    $clickType,
                    $clickId,
                    self::normalizeTime($paidAt ?? $conversionTime),
                    (float)($totals['total'] ?? $totals['net_paid'])
                );
            }
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function markExported(int $ownerId, array $rows, string $batch): void
    {
        foreach ($rows as $row) {
            $this->db->query('INSERT IGNORE INTO gourmet_conversion_exports (id_owner,lead_id,conversion_action,order_id,click_id_type,click_id_hash,conversion_time,value,currency,export_batch,payload_json) VALUES (:owner,:lead,:action,:order_id,:type,:hash,:time,:value,:currency,:batch,:payload)');
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':lead', (int)$row['lead_id']);
            $this->db->bind(':action', (string)$row['conversion_action']);
            $this->db->bind(':order_id', (string)$row['order_id']);
            $this->db->bind(':type', (string)$row['click_id_type']);
            $this->db->bind(':hash', hash('sha256', (string)$row['click_id']));
            $this->db->bind(':time', (string)$row['conversion_time']);
            $this->db->bind(':value', (float)$row['value']);
            $this->db->bind(':currency', 'USD');
            $this->db->bind(':batch', mb_substr($batch, 0, 80));
            $this->db->bind(':payload', json_encode(['lead_id' => $row['lead_id'], 'order_id' => $row['order_id']], JSON_UNESCAPED_SLASHES));
            $this->db->execute();
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Google Click ID', 'GBRAID', 'WBRAID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID', 'Lead ID']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['click_id_type'] === 'gclid' ? $row['click_id'] : '',
                $row['click_id_type'] === 'gbraid' ? $row['click_id'] : '',
                $row['click_id_type'] === 'wbraid' ? $row['click_id'] : '',
                $row['conversion_action'],
                $row['conversion_time'],
                number_format((float)$row['value'], 2, '.', ''),
                'USD',
                $row['order_id'],
                $row['lead_id'],
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return (string)$csv;
    }

    /** @return array{0:string,1:string} */
    private static function clickId(array $attribution): array
    {
        foreach (['gclid', 'gbraid', 'wbraid'] as $type) {
            $value = trim((string)($attribution[$type] ?? ''));
            if ($value !== '') {
                return [$type, $value];
            }
        }
        return ['', ''];
    }

    /** @return array<string,mixed> */
    private static function row(int $leadId, string $action, string $orderId, string $clickType, string $clickId, string $time, float $value): array
    {
        return [
            'lead_id' => $leadId,
            'conversion_action' => $action,
            'order_id' => $orderId,
            'click_id_type' => $clickType,
            'click_id' => $clickId,
            'conversion_time' => $time,
            'value' => round($value, 2),
            'currency' => 'USD',
        ];
    }

    private static function normalizeTime(string $value): string
    {
        try {
            $time = new DateTimeImmutable($value, GourmetLandingAttributionService::eventTimezone());
        } catch (Throwable) {
            $time = new DateTimeImmutable('now', GourmetLandingAttributionService::eventTimezone());
        }
        return GourmetLandingAttributionService::conversionTime($time);
    }
}
