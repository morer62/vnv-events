<?php

namespace App\Repositories;

class OrdersPaymentSplitRepository extends BaseRepository
{
    protected array $fields = [
        'id_order',
        'split_type',
        'first_percent',
        'first_amount',
        'second_percent',
        'second_amount',
        'is_closed',
        'locked_at'
    ];

    public function __construct()
    {
        $this->table = "orders_payment_split";
        $this->db = new Connection();
    }

    public function getByOrder(int $orderId): ?object
    {
        return $this->getOne(['id_order' => $orderId]);
    }

    public function updateSecondAmount(int $orderId, float $amount): bool
    {
        return $this->update([
            'second_amount' => $amount
        ], [
            'id_order' => $orderId
        ]);
    }

    public function markAsClosed(int $orderId): bool
    {
        return $this->update([
            'is_closed' => 1
        ], [
            'id_order' => $orderId
        ]);
    }
}
