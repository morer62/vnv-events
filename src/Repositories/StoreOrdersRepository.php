<?php

namespace App\Repositories;

class StoreOrdersRepository extends BaseRepository
{
    const PAYMENT_PENDING = 'PENDING';
    const PAYMENT_PAID = 'PAID';
    const PAYMENT_FAILED = 'FAILED';
    const PAYMENT_REFUNDED = 'REFUNDED';

    const STATUS_NEW = 'NEW';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_READY = 'READY';
    const STATUS_SENDING = 'READY';
    const STATUS_DELIVERED = 'DELIVERED';
    const STATUS_CANCELLED = 'CANCELLED';

    const PRICING_PAYG = 'PAYG';
    const PRICING_SUBSCRIPTION = 'SUBSCRIPTION';
    const PRICING_QUOTE = 'QUOTE';

    protected array $fields = [
        'id',
        'id_owner',
        'id_user',
        'id_cart',
        'public_token',
        'guest_name',
        'guest_email',
        'guest_phone',
        'city',
        'audience_type',
        'meal_style',
        'pricing_mode',
        'items_count',
        'meals_count',
        'subtotal',
        'discount',
        'coupon_code',
        'id_coupon',
        'coupon_discount',
        'total',
        'payment_status',
        'status',
        'billing_address_1',
        'billing_address_2',
        'billing_city',
        'billing_state',
        'billing_zip',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_city',
        'shipping_state',
        'shipping_zip',
        'notes',
        'created_at',
        'updated_at'
    ];

    public function __construct()
    {
        $this->table = "store_orders";
        $this->db = new Connection();
        $this->ensureExtraColumns();
    }

    private function ensureExtraColumns(): void
    {
        $columns = [
            'coupon_code' => 'VARCHAR(80) NULL AFTER `discount`',
            'id_coupon' => 'INT(11) NULL AFTER `coupon_code`',
            'coupon_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `id_coupon`',
            'billing_address_1' => 'VARCHAR(255) NULL AFTER `status`',
            'billing_address_2' => 'VARCHAR(255) NULL AFTER `billing_address_1`',
            'billing_city' => 'VARCHAR(120) NULL AFTER `billing_address_1`',
            'billing_state' => 'VARCHAR(120) NULL AFTER `billing_city`',
            'billing_zip' => 'VARCHAR(60) NULL AFTER `billing_state`',
            'shipping_address_1' => 'VARCHAR(255) NULL AFTER `billing_zip`',
            'shipping_address_2' => 'VARCHAR(255) NULL AFTER `shipping_address_1`',
            'shipping_city' => 'VARCHAR(120) NULL AFTER `shipping_address_1`',
            'shipping_state' => 'VARCHAR(120) NULL AFTER `shipping_city`',
            'shipping_zip' => 'VARCHAR(60) NULL AFTER `shipping_state`'
        ];

        foreach ($columns as $column => $definition) {
            if ($this->columnExists($column)) {
                continue;
            }

            try {
                $this->db->query("ALTER TABLE `{$this->table}` ADD COLUMN `{$column}` {$definition}");
                $this->db->execute();
            } catch (\Throwable $e) {
                // Avoid breaking runtime on environments with restricted DDL permissions.
            }
        }
    }

    private function columnExists(string $column): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE :column");
            $this->db->bind(':column', $column);
            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function generatePublicToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function getByPublicToken(string $token): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE public_token = :public_token
            LIMIT 1
        ");
        $this->db->bind(':public_token', $token);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getById(int $id): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $id, \PDO::PARAM_INT);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getAllByOwner(int $ownerId, int $limit = 100): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getRecentByOwnerAndStatuses(int $ownerId, array $statuses, int $limit = 50): array
    {
        $statuses = array_values(array_unique(array_filter(array_map('trim', $statuses))));
        if (!$statuses) {
            return [];
        }

        $holders = [];
        foreach ($statuses as $i => $_status) {
            $holders[] = ':status_' . $i;
        }

        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
              AND status IN (" . implode(',', $holders) . ")
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);

        foreach ($statuses as $i => $status) {
            $this->db->bind(':status_' . $i, $status);
        }

        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }

    public function markAsPaid(int $orderId): bool
    {
        return $this->update([
            'payment_status' => self::PAYMENT_PAID,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $orderId
        ]);
    }

    public function markAsFailed(int $orderId): bool
    {
        return $this->update([
            'payment_status' => self::PAYMENT_FAILED,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $orderId
        ]);
    }

    public function markAsRefunded(int $orderId): bool
    {
        return $this->update([
            'payment_status' => self::PAYMENT_REFUNDED,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $orderId
        ]);
    }

    public function updateStatus(int $orderId, string $status): bool
    {
        return $this->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $orderId
        ]);
    }

    public function assignUser(int $orderId, int $userId): bool
    {
        return $this->update([
            'id_user' => $userId,
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $orderId
        ]);
    }

    public function getFullOrderDetails(int $orderId): ?object
    {
        try {
            $this->db->query("
                SELECT *
                FROM {$this->table}
                WHERE id = :id
                LIMIT 1
            ");
            $this->db->bind(':id', $orderId, \PDO::PARAM_INT);
            $order = $this->db->fetchOne();

            if (!$order) {
                return null;
            }

            $itemsRepo = new StoreOrderItemsRepository();
            $paymentsRepo = new StorePaymentsRepository();

            $items = $itemsRepo->getDetailedByOrder((int)$orderId);
            $payments = $paymentsRepo->getByOrder((int)$orderId);
            $successfulPayments = $paymentsRepo->getSuccessfulByOrder((int)$orderId);
            $lastSuccessfulPayment = $paymentsRepo->getLastSuccessfulByOrder((int)$orderId);
            $totalPaid = $paymentsRepo->getTotalPaidByOrder((int)$orderId);

            $quantityTotal = 0;
            $itemsCount = 0;

            foreach ($items as $item) {
                if (is_object($item)) {
                    $item->variation_options = $this->decodeJsonArray($item->variation_options_snapshot ?? null);
                    $item->display_label = $this->buildItemDisplayLabel(
                        (string)($item->product_name_snapshot ?? ''),
                        (string)($item->variation_name_snapshot ?? '')
                    );
                    $quantityTotal += (int)($item->quantity ?? 0);
                } else {
                    $item['variation_options'] = $this->decodeJsonArray($item['variation_options_snapshot'] ?? null);
                    $item['display_label'] = $this->buildItemDisplayLabel(
                        (string)($item['product_name_snapshot'] ?? ''),
                        (string)($item['variation_name_snapshot'] ?? '')
                    );
                    $quantityTotal += (int)($item['quantity'] ?? 0);
                }

                $itemsCount++;
            }

            $order->items = $items;
            $order->payments = $payments;
            $order->successful_payments = $successfulPayments;
            $order->last_successful_payment = $lastSuccessfulPayment;
            $order->total_paid = round((float)$totalPaid, 2);
            $order->balance_due = round(max(0, (float)$order->total - (float)$totalPaid), 2);
            $order->is_paid_in_full = ((float)$totalPaid >= (float)$order->total);
            $order->quantity_total = $quantityTotal;
            $order->items_count = $itemsCount > 0 ? $itemsCount : (int)($order->items_count ?? 0);
            $order->meals_count = $quantityTotal > 0 ? $quantityTotal : (int)($order->meals_count ?? 0);

            return $order;
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return null;
        }
    }

    private function decodeJsonArray(?string $json): array
    {
        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildItemDisplayLabel(string $productName, string $variationName): string
    {
        $productName = trim($productName);
        $variationName = trim($variationName);

        if ($variationName !== '') {
            return $productName . ' - ' . $variationName;
        }

        return $productName;
    }

    public function createFromCart(object $cart, array $override = []): bool
    {
        $data = [
            'id_user' => $cart->id_user ?? null,
            'id_cart' => $cart->id ?? null,
            'public_token' => $this->generatePublicToken(),
            'guest_name' => $cart->guest_name ?? null,
            'guest_email' => $cart->guest_email ?? null,
            'guest_phone' => $cart->guest_phone ?? null,
            'city' => $cart->city ?? null,
            'audience_type' => null,
            'meal_style' => null,
            'pricing_mode' => self::PRICING_PAYG,
            'items_count' => (int)($cart->items_count ?? 0),
            'meals_count' => (int)($cart->meals_count ?? 0),
            'subtotal' => (float)($cart->subtotal ?? 0),
            'discount' => (float)($cart->discount ?? 0),
            'coupon_code' => $cart->coupon_code ?? null,
            'id_coupon' => $cart->id_coupon ?? null,
            'coupon_discount' => (float)($cart->coupon_discount ?? $cart->discount ?? 0),
            'total' => (float)($cart->total ?? 0),
            'payment_status' => self::PAYMENT_PENDING,
            'status' => self::STATUS_NEW,
            'notes' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        foreach ($override as $key => $value) {
            $data[$key] = $value;
        }

        return $this->add($data);
    }

    public function getAllByUser(int $userId, int $limit = 100): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_user = :id_user
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_user', $userId, \PDO::PARAM_INT);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getAllByGuestEmail(string $email, int $limit = 100): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE guest_email = :guest_email
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':guest_email', $email);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function hasAnyPaidOrderForCustomer(int $ownerId, ?int $userId, ?string $email): bool
    {
        $userId = $userId ? (int)$userId : 0;
        $email = strtolower(trim((string)$email));

        if ($userId <= 0 && $email === '') {
            return false;
        }

        $sql = "
            SELECT 1
            FROM {$this->table}
            WHERE id_owner = :id_owner
              AND payment_status = :payment_status
              AND (
                (:id_user > 0 AND id_user = :id_user)
                OR (:guest_email <> '' AND LOWER(guest_email) = :guest_email)
              )
            LIMIT 1
        ";

        $this->db->query($sql);
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->db->bind(':payment_status', self::PAYMENT_PAID);
        $this->db->bind(':id_user', $userId, \PDO::PARAM_INT);
        $this->db->bind(':guest_email', $email);

        return (bool)$this->db->fetchOne();
    }
}