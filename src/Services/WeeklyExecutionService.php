<?php

namespace App\Services;

use App\Repositories\Connection;
use App\Repositories\OrderExecutionFilesRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\UserRepository;

final class WeeklyExecutionService
{
    public function listReadyEvents(int $ownerId, string $startDate, string $endDate): array
    {
        $orders = (new OrdersRepository())->getExecutionCandidates($ownerId, $startDate, $endDate);
        $users = new UserRepository();
        $files = new OrderExecutionFilesRepository();
        $result = [];

        foreach ($orders as $order) {
            $readiness = $this->paymentReadiness($order);
            if (!$readiness['is_paid']) {
                continue;
            }

            $client = $users->getOneWithoutOwnership(['id' => (int)$order->id_client]);
            $order->client_name = $client
                ? trim((string)($client->name ?? '') . ' ' . (string)($client->lastname ?? ''))
                : 'Client #' . (int)$order->id_client;
            $order->client_email = (string)($client->email ?? '');
            $manager = !empty($order->main_manager_id)
                ? $users->getOneWithoutOwnership(['id' => (int)$order->main_manager_id])
                : null;
            $order->manager_name = $manager
                ? trim((string)($manager->name ?? '') . ' ' . (string)($manager->lastname ?? ''))
                : '';
            $order->execution_files = $files->getAllBy(['id_order' => (int)$order->id]);
            $order->paid_total = $readiness['paid'];
            $order->order_total = $readiness['total'];
            $order->contract_token = $this->orderAccessToken($order);
            $result[] = $order;
        }

        usort($result, static function ($a, $b): int {
            $left = sprintf('%s %s', $a->event_date ?? '', $a->start_time ?? '00:00:00');
            $right = sprintf('%s %s', $b->event_date ?? '', $b->start_time ?? '00:00:00');
            return strcmp($left, $right) ?: ((int)$a->id <=> (int)$b->id);
        });
        return $result;
    }

    public function paymentReadiness(object $order): array
    {
        $main = OrderCalculatorService::calculateTotal($order);
        $mainTotal = round(max(0, (float)$main['total']), 2);
        $mainPaid = $this->paidFor((int)$order->id, null) + $this->advancesFor((int)$order->id, null);
        $mainReady = $mainTotal > 0
            ? $mainPaid + 0.01 >= $mainTotal
            : (string)($order->status_workflow ?? '') === 'INVOICE_PAID';

        $total = $mainTotal;
        $paid = min($mainPaid, $mainTotal);
        $allSubordersReady = true;
        $suborders = (new OrdersSuborderRepository())->getAllBy([
            'id_order' => (int)$order->id,
            'is_archived' => 0,
        ]);
        $assigned = new OrderSuborderServicesAssignedRepository();

        foreach ($suborders as $suborder) {
            $subtotal = 0.0;
            foreach ($assigned->getServicesWithDetails((int)$suborder->id) as $service) {
                $subtotal += (float)($service->quantity ?? 0) * (float)($service->actual_price ?? 0);
            }
            $base = max(0, $subtotal - (float)($suborder->discount_value ?? 0));
            $subTotal = round($base * (1 + ((float)($suborder->tax_percertance ?? 0) / 100)), 2);
            $subPaid = $this->paidFor((int)$order->id, (int)$suborder->id)
                + $this->advancesFor((int)$order->id, (int)$suborder->id);
            $subReady = $subTotal > 0
                ? $subPaid + 0.01 >= $subTotal
                : (string)($suborder->status_workflow ?? '') === 'INVOICE_PAID';
            $allSubordersReady = $allSubordersReady && $subReady;
            $total += $subTotal;
            $paid += min($subPaid, $subTotal);
        }

        return [
            'is_paid' => $mainReady && $allSubordersReady,
            'total' => round($total, 2),
            'paid' => round($paid, 2),
        ];
    }

    private function paidFor(int $orderId, ?int $suborderId): float
    {
        $payments = new OrdersPaymentsRepository();
        $rows = $suborderId
            ? $payments->getAllBy(['id_suborder' => $suborderId])
            : $payments->getAllBy(['id_order' => $orderId]);
        $paid = 0.0;
        foreach ($rows as $payment) {
            $rowSuborderId = (int)($payment->id_suborder ?? 0);
            if ($suborderId === null && $rowSuborderId !== 0) {
                continue;
            }
            $paid += max(0, (float)($payment->amount ?? 0) - (float)($payment->refunded_amount ?? 0));
        }
        return $paid;
    }

    private function advancesFor(int $orderId, ?int $suborderId): float
    {
        try {
            $db = new Connection();
            if ($suborderId !== null) {
                $db->query('SELECT COALESCE(SUM(amount), 0) AS total FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1');
                $db->bind(':id', $suborderId);
            } else {
                $db->query('SELECT COALESCE(SUM(amount), 0) AS total FROM orders_advances WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)');
                $db->bind(':id', $orderId);
            }
            $row = $db->fetchOne();
            return (float)($row->total ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function orderAccessToken(object $order): string
    {
        $payload = [
            'order_id' => (int)$order->id,
            'user_id' => (int)$order->id_client,
            'exp' => time() + (86400 * 30),
        ];
        $secret = $_ENV['VNV_SECRET_KEY'] ?? 'mySuperSecretKey';
        $payload['hash'] = hash_hmac('sha256', json_encode($payload), $secret);
        return base64_encode(json_encode($payload));
    }
}
