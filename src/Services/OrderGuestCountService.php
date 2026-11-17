<?php

namespace App\Services;

use App\Repositories\OrdersServicesAssignedRepository;

final class OrderGuestCountService
{
    public function resolve(object $order): ?int
    {
        $notes = (string)($order->notes ?? '');
        if ($notes !== '' && preg_match('/(?:guest\s*count|number\s+of\s+guests|guests?)\s*[:\-]\s*([0-9][0-9,]*)/i', $notes, $match)) {
            $count = (int)str_replace(',', '', $match[1]);
            if ($count > 0) {
                return $count;
            }
        }

        $orderId = (int)($order->id ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $largestQuantity = 0;
        foreach ((new OrdersServicesAssignedRepository())->getAllWithoutOwner(['id_order' => $orderId]) as $service) {
            $largestQuantity = max($largestQuantity, (int)($service->quantity ?? 0));
        }

        // A quantity of one normally represents one flat-rate service, not one guest.
        return $largestQuantity > 1 ? $largestQuantity : null;
    }
}
