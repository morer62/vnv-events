<?php

namespace App\Services;

use App\Repositories\Connection;

final class StoreDeliveryAvailabilityService
{
    public function availability(int $ownerId, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?: new \DateTimeImmutable('now');
        $available = [];

        try {
            $db = new Connection();
            $db->query("SELECT DISTINCT u.id, u.name, u.lastname
                FROM store_user_roles sur
                INNER JOIN users u ON u.id = sur.id_user AND u.is_active = 1
                INNER JOIN payroll_time_logs ptl ON ptl.id_user = u.id
                  AND ptl.id_owner = sur.id_owner AND ptl.end_time IS NULL
                WHERE sur.id_owner = :owner AND LOWER(sur.role) = 'delivery'");
            $db->bind(':owner', $ownerId, \PDO::PARAM_INT);
            $available = $db->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Store delivery availability] ' . $e->getMessage());
        }

        $slots = [];
        $cursor = $now->modify('+1 day')->setTime(11, 0);
        for ($i = 0; $i < 3; $i++) {
            $slots[] = [
                'value' => $cursor->format('Y-m-d\TH:i'),
                'label' => $cursor->format('D, M j \a\t g:i A'),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'asap_available' => count($available) > 0,
            'available_driver_count' => count($available),
            'asap_estimate' => count($available) > 0 ? $now->modify('+2 hours')->format('Y-m-d H:i:s') : null,
            'next_slots' => $slots,
        ];
    }
}
