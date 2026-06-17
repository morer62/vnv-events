<?php

namespace App\Repositories;

class StoreOrderWorkflowRepository extends BaseRepository
{
    protected string $table = 'store_order_workflow';

    protected array $fields = [
        'id',
        'id_owner',
        'id_store_order',
        'kitchen_user_id',
        'delivery_user_id',
        'approved_at',
        'kitchen_ready_at',
        'sent_at',
        'delivered_at',
        'allow_team_close_delivery',
        'allow_chat_with_client',
        'delivery_photo_url',
        'delivery_notes',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->db = new Connection();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_owner INT NOT NULL,
                id_store_order INT NOT NULL,
                kitchen_user_id INT NULL,
                delivery_user_id INT NULL,
                approved_at DATETIME NULL,
                kitchen_ready_at DATETIME NULL,
                sent_at DATETIME NULL,
                delivered_at DATETIME NULL,
                delivery_photo_url TEXT NULL,
                delivery_notes TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_store_order_workflow_order (id_store_order),
                INDEX idx_store_order_workflow_owner (id_owner),
                INDEX idx_store_order_workflow_kitchen (kitchen_user_id),
                INDEX idx_store_order_workflow_delivery (delivery_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->db->execute();
    }

    public function getByOrder(int $orderId): ?object
    {
        return $this->getOne(['id_store_order' => $orderId]) ?: null;
    }

    public function getMapByOrders(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        $orderIds = array_map('intval', $orderIds);
        $placeholders = [];
        foreach ($orderIds as $idx => $_id) {
            $placeholders[] = ':id_' . $idx;
        }

        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_store_order IN (" . implode(',', $placeholders) . ")
        ");
        foreach ($orderIds as $idx => $id) {
            $this->db->bind(':id_' . $idx, $id, \PDO::PARAM_INT);
        }

        $rows = $this->db->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row->id_store_order] = $row;
        }
        return $map;
    }

    public function upsertAssignments(int $ownerId, int $orderId, ?int $kitchenUserId, ?int $deliveryUserId): bool
    {
        $existing = $this->getByOrder($orderId);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->update([
                'kitchen_user_id' => $kitchenUserId,
                'delivery_user_id' => $deliveryUserId,
                'updated_at' => $now
            ], ['id' => (int)$existing->id]);
        }

        return $this->add([
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'kitchen_user_id' => $kitchenUserId,
            'delivery_user_id' => $deliveryUserId,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    public function markApprovedToKitchen(int $ownerId, int $orderId, int $kitchenUserId): bool
    {
        $existing = $this->getByOrder($orderId);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->update([
                'kitchen_user_id' => $kitchenUserId,
                'approved_at' => $existing->approved_at ?: $now,
                'updated_at' => $now
            ], ['id' => (int)$existing->id]);
        }

        return $this->add([
            'id_owner' => $ownerId,
            'id_store_order' => $orderId,
            'kitchen_user_id' => $kitchenUserId,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    public function markKitchenReady(int $orderId): bool
    {
        $existing = $this->getByOrder($orderId);
        if (!$existing) {
            return false;
        }

        return $this->update([
            'kitchen_ready_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => (int)$existing->id]);
    }

    public function markSending(int $orderId): bool
    {
        $existing = $this->getByOrder($orderId);
        if (!$existing) {
            return false;
        }

        return $this->update([
            'sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => (int)$existing->id]);
    }

    public function markDelivered(int $orderId, ?string $photoUrl, ?string $notes): bool
    {
        $existing = $this->getByOrder($orderId);
        if (!$existing) {
            return false;
        }

        return $this->update([
            'delivered_at' => date('Y-m-d H:i:s'),
            'delivery_photo_url' => $photoUrl ?? ($existing->delivery_photo_url ?? null),
            'delivery_notes' => $notes ?? ($existing->delivery_notes ?? null),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => (int)$existing->id]);
    }

    public function getKitchenOrders(int $ownerId, int $kitchenUserId): array
    {
        $this->db->query("
            SELECT so.*, sow.kitchen_user_id, sow.delivery_user_id, sow.approved_at, sow.kitchen_ready_at
            FROM store_orders so
            INNER JOIN {$this->table} sow ON sow.id_store_order = so.id
            WHERE so.id_owner = :id_owner
              AND sow.kitchen_user_id = :kitchen_user_id
              AND so.status IN ('CONFIRMED', 'PROCESSING', 'IN_PREPARATION', 'READY', 'READY_FOR_DELIVERY')
            ORDER BY so.created_at ASC
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':kitchen_user_id', $kitchenUserId, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }

    public function getKitchenOrdersQueue(int $ownerId): array
    {
        $this->db->query("
            SELECT so.*, sow.kitchen_user_id, sow.delivery_user_id, sow.approved_at, sow.kitchen_ready_at
            FROM store_orders so
            LEFT JOIN {$this->table} sow ON sow.id_store_order = so.id
            WHERE so.id_owner = :id_owner
              AND so.payment_status = 'PAID'
              AND so.status IN ('NEW', 'CONFIRMED', 'PROCESSING', 'IN_PREPARATION', 'READY', 'READY_FOR_DELIVERY')
            ORDER BY so.created_at ASC
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }

    public function getDeliveryOrders(int $ownerId, int $deliveryUserId): array
    {
        $this->db->query("
            SELECT so.*, sow.delivery_user_id, sow.sent_at, sow.delivered_at, sow.delivery_photo_url, sow.delivery_notes
            FROM store_orders so
            INNER JOIN {$this->table} sow ON sow.id_store_order = so.id
            WHERE so.id_owner = :id_owner
              AND sow.delivery_user_id = :delivery_user_id
              AND (
                so.status IN ('READY', 'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPTED', 'REDELIVERY_SCHEDULED', 'DELIVERED', 'COMPLETED')
                OR COALESCE(TRIM(so.status), '') = ''
              )
            ORDER BY so.created_at ASC
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':delivery_user_id', $deliveryUserId, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }
}

