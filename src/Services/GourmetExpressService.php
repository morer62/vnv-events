<?php

namespace App\Services;

use App\Repositories\Connection;

final class GourmetExpressService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    public function settings(int $ownerId, string $siteKey): array
    {
        $this->db->query('SELECT * FROM store_gourmet_settings WHERE id_owner=:owner AND site_key=:site LIMIT 1');
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':site', $siteKey);
        $row = $this->db->fetchOne();
        return $row ? (array)$row : [];
    }

    public function assertStoreOpen(int $ownerId, string $siteKey): void
    {
        $settings = $this->settings($ownerId, $siteKey);
        if (!$settings || !(int)($settings['store_paused'] ?? 0)) return;

        $reopen = trim((string)($settings['reopen_at'] ?? ''));
        if ($reopen !== '' && strtotime($reopen) !== false && strtotime($reopen) <= time()) {
            $this->db->query('UPDATE store_gourmet_settings SET store_paused=0,reopen_at=NULL,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site');
            $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
            $this->db->bind(':site', $siteKey);
            $this->db->execute();
            return;
        }

        throw new \DomainException(trim((string)($settings['pause_message'] ?? '')) ?: 'VNV Gourmet To Go is temporarily pausing new orders. You can still browse the menu.');
    }

    /** @param array<int,array<string,mixed>|object> $items */
    public function assertCartComposition(array $items): void
    {
        $hasMain = false;
        $hasAddon = false;
        foreach ($items as $item) {
            $row = is_object($item) ? (array)$item : $item;
            $role = strtoupper((string)($row['product_role'] ?? 'MAIN'));
            $hasAddon = $hasAddon || $role === 'ADDON' || (int)($row['is_addon_only'] ?? 0) === 1;
            $hasMain = $hasMain || in_array($role, ['MAIN', 'BUNDLE'], true);
        }
        if ($hasAddon && !$hasMain) throw new \DomainException('Add-ons must be ordered with an eligible main dish or bundle.');
    }

    /** @param array<int,object> $cartItems */
    public function assertSchedule(int $ownerId, string $siteKey, array $cartItems, string $localDateTime): array
    {
        $settings = $this->settings($ownerId, $siteKey);
        $timezone = new \DateTimeZone((string)($settings['timezone'] ?? 'America/New_York'));
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $localDateTime, $timezone);
        if (!$local) throw new \InvalidArgumentException('Choose a valid delivery date and time.');

        $requiredHours = (int)($settings['minimum_order_notice_hours'] ?? 24);
        foreach ($cartItems as $item) {
            $this->db->query('SELECT p.lead_hours,v.lead_hours variation_lead FROM store_products p LEFT JOIN store_product_variations v ON v.id=:variation WHERE p.id=:product LIMIT 1');
            $this->db->bind(':variation', (int)($item->id_product_variation ?? 0), \PDO::PARAM_INT);
            $this->db->bind(':product', (int)$item->id_product, \PDO::PARAM_INT);
            $lead = $this->db->fetchOne();
            $requiredHours = max($requiredHours, (int)($lead->variation_lead ?? 0), (int)($lead->lead_hours ?? 0));
        }
        if ($local < (new \DateTimeImmutable('now', $timezone))->modify('+' . $requiredHours . ' hours')) {
            throw new \DomainException('This menu requires at least ' . $requiredHours . ' hours notice.');
        }

        $time = $local->format('H:i:s');
        $this->db->query("SELECT * FROM store_gourmet_windows WHERE id_owner=:owner AND site_key=:site AND status='ACTIVE' AND :clock>=start_time AND :clock2<end_time LIMIT 1");
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT); $this->db->bind(':site', $siteKey);
        $this->db->bind(':clock', $time); $this->db->bind(':clock2', $time);
        $window = $this->db->fetchOne();
        if (!$window) throw new \DomainException('Choose one of the available delivery windows: 10–12, 12–2, 2–4 or 4–6.');

        $date = $local->format('Y-m-d');
        $this->db->query("SELECT COUNT(*) total FROM store_orders WHERE id_owner=:owner AND site_key=:site AND status NOT IN ('CANCELLED','RETURNED','CLOSED') AND DATE(requested_delivery_at)=:day");
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT); $this->db->bind(':site', $siteKey); $this->db->bind(':day', $date);
        $daily = (int)($this->db->fetchOne()->total ?? 0);
        if ($daily >= (int)($settings['daily_capacity'] ?? 25)) throw new \DomainException('That day has reached capacity. Please choose another date.');

        $this->db->query("SELECT COUNT(*) total FROM store_orders WHERE id_owner=:owner AND site_key=:site AND status NOT IN ('CANCELLED','RETURNED','CLOSED') AND DATE(requested_delivery_at)=:day AND TIME(requested_delivery_at)>=:start AND TIME(requested_delivery_at)<:end");
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT); $this->db->bind(':site', $siteKey); $this->db->bind(':day', $date);
        $this->db->bind(':start', (string)$window->start_time); $this->db->bind(':end', (string)$window->end_time);
        if ((int)($this->db->fetchOne()->total ?? 0) >= (int)$window->capacity) throw new \DomainException('That delivery window is full. Please choose another window.');

        return ['local'=>$local->format('Y-m-d H:i:s'),'utc'=>$local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'timezone'=>$timezone->getName(),'lead_hours'=>$requiredHours,'window_id'=>(int)$window->id,'window_label'=>(string)($window->label ?? '')];
    }

    public function deliveryZone(int $ownerId, string $siteKey, string $zip, float $subtotal): array
    {
        $this->db->query("SELECT * FROM store_gourmet_delivery_zones WHERE id_owner=:owner AND site_key=:site AND status='ACTIVE'");
        $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT); $this->db->bind(':site', $siteKey);
        foreach ($this->db->fetchAll() ?: [] as $zone) {
            $zips = json_decode((string)($zone->zip_codes_json ?? '[]'), true) ?: [];
            if (!in_array(trim($zip), array_map('strval', $zips), true)) continue;
            $fee = $subtotal >= (float)$zone->free_delivery_threshold ? 0.0 : (float)$zone->delivery_fee;
            return ['name'=>(string)$zone->zone_name,'fee'=>$fee,'free_threshold'=>(float)$zone->free_delivery_threshold];
        }
        throw new \DomainException('This ZIP code is outside the VNV Gourmet To Go delivery area.');
    }
}
