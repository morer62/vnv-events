<?php

namespace App\Repositories;

class StoreOrderTasksRepository extends StoreRepository
{
    public const TYPE_PREPARATION = 'PREPARATION';
    public const TYPE_ASSISTANCE = 'ASSISTANCE';
    public const TYPE_DELIVERY = 'DELIVERY';
    public const TYPE_FULFILLMENT = 'FULFILLMENT';
    public const TYPE_CUSTOMER_SUPPORT = 'CUSTOMER_SUPPORT';
    public const TYPE_OTHER = 'OTHER';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_WAITING_REVIEW = 'WAITING_REVIEW';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELED = 'CANCELED';

    public function __construct()
    {
        $this->table = 'store_order_tasks';
        $this->db = new Connection();
    }

    public function getByOrder(int $ownerId, int $orderId): array
    {
        $this->db->query("
            SELECT t.*, u.name, u.lastname, u.email
            FROM {$this->table} t
            LEFT JOIN users u ON u.id = t.id_user
            WHERE t.id_owner = :owner AND t.id_store_order = :order
            ORDER BY t.created_at ASC, t.id ASC
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order', $orderId);
        return $this->db->fetchAll();
    }

    public function getForAssignee(int $ownerId, int $userId): array
    {
        $this->db->query("
            SELECT t.*, o.guest_name, o.guest_email, o.id_user AS client_user_id, o.status AS order_status,
                   o.payment_status, o.shipping_address_1, o.shipping_city,
                   o.shipping_state, o.shipping_zip, o.public_token,
                   w.allow_team_close_delivery, w.allow_chat_with_client,
                   w.delivery_photo_url, w.delivery_notes
            FROM {$this->table} t
            INNER JOIN store_orders o ON o.id = t.id_store_order AND o.id_owner = t.id_owner
            LEFT JOIN store_order_workflow w ON w.id_store_order = t.id_store_order
            WHERE t.id_owner = :owner AND t.id_user = :user
            ORDER BY FIELD(t.status, 'IN_PROGRESS', 'PENDING', 'WAITING_REVIEW', 'COMPLETED', 'CANCELED'),
                     t.created_at DESC, t.id DESC
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':user', $userId);
        return $this->db->fetchAll();
    }

    public function getOneForAssignee(int $taskId, int $ownerId, int $userId): ?object
    {
        $this->db->query("
            SELECT t.*, o.guest_name, o.guest_email, o.id_user AS client_user_id, o.status AS order_status,
                   w.allow_team_close_delivery, w.allow_chat_with_client
            FROM {$this->table} t
            INNER JOIN store_orders o ON o.id = t.id_store_order AND o.id_owner = t.id_owner
            LEFT JOIN store_order_workflow w ON w.id_store_order = t.id_store_order
            WHERE t.id = :id AND t.id_owner = :owner AND t.id_user = :user
            LIMIT 1
        ");
        $this->db->bind(':id', $taskId);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':user', $userId);
        return $this->db->fetchOne() ?: null;
    }

    public function getOneForOwner(int $taskId, int $ownerId): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id = :id AND id_owner = :owner LIMIT 1");
        $this->db->bind(':id', $taskId);
        $this->db->bind(':owner', $ownerId);
        return $this->db->fetchOne() ?: null;
    }

    public function getAssigneesByOrders(int $ownerId, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($ownerId <= 0 || empty($orderIds)) {
            return [];
        }

        $placeholders = [];
        foreach ($orderIds as $index => $orderId) {
            $placeholders[] = ':order' . $index;
        }
        $placeholdersSql = implode(',', $placeholders);
        $this->db->query("
            SELECT DISTINCT t.id_store_order, u.id, u.name, u.lastname, u.email, u.level, u.allow_chat_with_clients
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            WHERE t.id_owner = :owner
              AND t.id_store_order IN ({$placeholdersSql})
              AND t.id_user IS NOT NULL
              AND t.status <> 'CANCELED'
            ORDER BY u.name ASC, u.lastname ASC, u.email ASC
        ");
        $this->db->bind(':owner', $ownerId);
        foreach ($orderIds as $index => $orderId) {
            $this->db->bind(':order' . $index, $orderId);
        }
        $rows = $this->db->fetchAll();
        $contactsByOrder = [];
        foreach ($rows as $row) {
            $contactsByOrder[(int)$row->id_store_order][] = $row;
        }

        return $contactsByOrder;
    }

    public function assigneeCanChatWithClient(int $ownerId, int $userId, int $clientId): bool
    {
        if ($ownerId <= 0 || $userId <= 0 || $clientId <= 0) {
            return false;
        }

        $this->db->query("
            SELECT t.id
            FROM {$this->table} t
            INNER JOIN store_orders o ON o.id = t.id_store_order AND o.id_owner = t.id_owner
            INNER JOIN store_order_workflow w ON w.id_store_order = t.id_store_order
            WHERE t.id_owner = :owner
              AND t.id_user = :user
              AND o.id_user = :client
              AND t.status <> 'CANCELED'
              AND w.allow_chat_with_client = 1
            LIMIT 1
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':user', $userId);
        $this->db->bind(':client', $clientId);

        return (bool)$this->db->fetchOne();
    }

    public function createTask(int $ownerId, int $orderId, ?int $userId, string $type, string $title, ?string $instructions = null, bool $requiresLocation = false, bool $allowAssigneeComplete = true): bool
    {
        if (!in_array($type, $this->types(), true) || trim($title) === '') {
            return false;
        }

        $this->db->query("
            INSERT INTO {$this->table}
                (id_owner, id_store_order, id_user, task_type, title, instructions, status,
                 requires_location, allow_assignee_complete, created_at, updated_at)
            VALUES
                (:owner, :order, :user, :type, :title, :instructions, :status,
                 :requires_location, :allow_complete, NOW(), NOW())
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order', $orderId);
        $this->db->bind(':user', $userId);
        $this->db->bind(':type', $type);
        $this->db->bind(':title', trim($title));
        $this->db->bind(':instructions', $instructions);
        $this->db->bind(':status', self::STATUS_PENDING);
        $this->db->bind(':requires_location', $requiresLocation ? 1 : 0);
        $this->db->bind(':allow_complete', $allowAssigneeComplete ? 1 : 0);
        return (bool)$this->db->execute();
    }

    public function ensureAssignment(int $ownerId, int $orderId, int $userId, string $type, string $title, bool $requiresLocation): bool
    {
        $this->db->query("
            SELECT id FROM {$this->table}
            WHERE id_owner = :owner AND id_store_order = :order AND id_user = :user
              AND task_type = :type AND status <> 'CANCELED'
            LIMIT 1
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order', $orderId);
        $this->db->bind(':user', $userId);
        $this->db->bind(':type', $type);
        if ($this->db->fetchOne()) {
            return true;
        }

        return $this->createTask($ownerId, $orderId, $userId, $type, $title, null, $requiresLocation);
    }

    public function replaceAssignmentByType(int $ownerId, int $orderId, ?int $userId, string $type, string $title, bool $requiresLocation): bool
    {
        if (!in_array($type, $this->types(), true)) {
            return false;
        }

        $this->db->query("
            UPDATE {$this->table}
            SET status = :canceled, updated_at = NOW()
            WHERE id_owner = :owner
              AND id_store_order = :order
              AND task_type = :type
              AND status <> :completed
              AND status <> :canceled
              AND (:user IS NULL OR id_user <> :user)
        ");
        $this->db->bind(':canceled', self::STATUS_CANCELED);
        $this->db->bind(':completed', self::STATUS_COMPLETED);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order', $orderId);
        $this->db->bind(':type', $type);
        $this->db->bind(':user', $userId);
        $this->db->execute();

        if (!$userId) {
            return true;
        }

        return $this->ensureAssignment($ownerId, $orderId, $userId, $type, $title, $requiresLocation);
    }

    public function getActiveByOrderAndType(int $ownerId, int $orderId, string $type): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :owner
              AND id_store_order = :order
              AND task_type = :type
              AND status <> :canceled
            ORDER BY created_at ASC, id ASC
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order', $orderId);
        $this->db->bind(':type', $type);
        $this->db->bind(':canceled', self::STATUS_CANCELED);
        return $this->db->fetchAll();
    }

    public function hasCompletedTaskType(int $ownerId, int $orderId, string $type): bool
    {
        $this->db->query("
            SELECT id
            FROM {$this->table}
            WHERE id_owner = :owner
              AND id_store_order = :order
              AND task_type = :type
              AND status = :completed
            LIMIT 1
        ");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order', $orderId);
        $this->db->bind(':type', $type);
        $this->db->bind(':completed', self::STATUS_COMPLETED);
        return (bool)$this->db->fetchOne();
    }

    public function updateTaskStatus(int $taskId, string $status, ?int $completedBy = null, ?string $notes = null): bool
    {
        if (!in_array($status, $this->statuses(), true)) {
            return false;
        }

        $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($status === self::STATUS_IN_PROGRESS) {
            $data['started_at'] = date('Y-m-d H:i:s');
        }
        if ($status === self::STATUS_COMPLETED) {
            $data['completed_at'] = date('Y-m-d H:i:s');
            $data['completed_by'] = $completedBy;
        }
        if ($notes !== null && trim($notes) !== '') {
            $data['notes'] = trim($notes);
        }
        return $this->update($data, ['id' => $taskId]);
    }

    public function types(): array
    {
        return [
            self::TYPE_PREPARATION,
            self::TYPE_ASSISTANCE,
            self::TYPE_DELIVERY,
            self::TYPE_FULFILLMENT,
            self::TYPE_CUSTOMER_SUPPORT,
            self::TYPE_OTHER,
        ];
    }

    public function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_WAITING_REVIEW,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELED,
        ];
    }
}
