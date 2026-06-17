<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StoreOrdersRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const PAYMENT_PENDING = 'PENDING';
    const PAYMENT_PAID = 'PAID';
    const PAYMENT_FAILED = 'FAILED';
    const PAYMENT_REFUNDED = 'REFUNDED';

    const STATUS_NEW = 'NEW';
    const STATUS_CONFIRMED = 'CONFIRMED';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_IN_PREPARATION = 'IN_PREPARATION';
    const STATUS_READY = 'READY';
    const STATUS_READY_FOR_DELIVERY = 'READY_FOR_DELIVERY';
    const STATUS_OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    const STATUS_DELIVERY_ATTEMPTED = 'DELIVERY_ATTEMPTED';
    const STATUS_RETURNED_TO_BUSINESS = 'RETURNED_TO_BUSINESS';
    const STATUS_REDELIVERY_SCHEDULED = 'REDELIVERY_SCHEDULED';
    const STATUS_SENDING = 'READY';
    const STATUS_DELIVERED = 'DELIVERED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CANCELLED = 'CANCELLED';
    const STATUS_RETURN_REQUESTED = 'RETURN_REQUESTED';
    const STATUS_RETURN_APPROVED = 'RETURN_APPROVED';
    const STATUS_RETURN_REJECTED = 'RETURN_REJECTED';
    const STATUS_RETURNED = 'RETURNED';
    const STATUS_CLOSED = 'CLOSED';

    const PRICING_PAYG = 'PAYG';
    const PRICING_SUBSCRIPTION = 'SUBSCRIPTION';
    const PRICING_QUOTE = 'QUOTE';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
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
        'cooked_at',
        'expiration_date',
        'notes',
        'return_notes',
        'return_requested_at',
        'return_admin_message',
        'return_decision_at',
        'return_closed_at',
        'created_at',
        'updated_at'
    ];

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_PROCESSING => 'Paid / Preparing',
            self::STATUS_IN_PREPARATION => 'In preparation',
            self::STATUS_READY => 'Ready',
            self::STATUS_READY_FOR_DELIVERY => 'Ready for delivery',
            self::STATUS_OUT_FOR_DELIVERY => 'Out for delivery',
            self::STATUS_DELIVERY_ATTEMPTED => 'Delivery attempted',
            self::STATUS_RETURNED_TO_BUSINESS => 'Returned to business',
            self::STATUS_REDELIVERY_SCHEDULED => 'Redelivery scheduled',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_RETURN_REQUESTED => 'Return requested',
            self::STATUS_RETURN_APPROVED => 'Return approved',
            self::STATUS_RETURN_REJECTED => 'Return rejected',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        $status = strtoupper(trim((string)$status));
        $options = self::statusOptions();

        return $options[$status] ?? ($status !== '' ? ucwords(strtolower(str_replace('_', ' ', $status))) : 'New');
    }

    public static function statusBadgeClass(?string $status): string
    {
        return match (strtoupper(trim((string)$status))) {
            self::STATUS_PROCESSING,
            self::STATUS_IN_PREPARATION,
            self::STATUS_CONFIRMED => 'bg-info text-dark',
            self::STATUS_READY,
            self::STATUS_READY_FOR_DELIVERY,
            self::STATUS_OUT_FOR_DELIVERY,
            self::STATUS_DELIVERY_ATTEMPTED,
            self::STATUS_REDELIVERY_SCHEDULED => 'bg-warning text-dark',
            self::STATUS_DELIVERED,
            self::STATUS_COMPLETED => 'bg-success',
            self::STATUS_CANCELLED,
            self::STATUS_RETURN_REJECTED => 'bg-danger',
            self::STATUS_RETURN_REQUESTED,
            self::STATUS_RETURN_APPROVED,
            self::STATUS_RETURNED,
            self::STATUS_RETURNED_TO_BUSINESS,
            self::STATUS_CLOSED => 'bg-secondary',
            default => 'bg-secondary',
        };
    }

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

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getByPublicToken(string $token, ?string $siteKey = null): ?object
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE public_token = :public_token
              {$siteSql}
            LIMIT 1
        ");
        $this->db->bind(':public_token', $token);
        $this->bindSiteScope($siteKey);

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

    public function getAllByOwner(int $ownerId, int $limit = 100, ?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
            {$siteSql}
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getRecentByOwnerAndStatuses(int $ownerId, array $statuses, int $limit = 50, ?string $siteKey = null): array
    {
        $statuses = array_values(array_unique(array_filter(array_map('trim', $statuses))));
        if (!$statuses) {
            return [];
        }

        $holders = [];
        foreach ($statuses as $i => $_status) {
            $holders[] = ':status_' . $i;
        }

        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
              AND status IN (" . implode(',', $holders) . ")
              {$siteSql}
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);

        foreach ($statuses as $i => $status) {
            $this->db->bind(':status_' . $i, $status);
        }

        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);
        return $this->db->fetchAll();
    }

    public function getByOwnerAndDateRange(
        int $ownerId,
        string $fromDateTime,
        string $toDateTime,
        int $limit = 250,
        ?string $siteKey = null
    ): array {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_owner = :id_owner
              AND created_at BETWEEN :from_dt AND :to_dt
              {$siteSql}
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);
        $this->db->bind(':from_dt', $fromDateTime);
        $this->db->bind(':to_dt', $toDateTime);
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

    public function getAllByUser(int $userId, int $limit = 100, ?int $ownerId = null, ?string $siteKey = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_user = :id_user
              {$ownerSql}
              {$siteSql}
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':id_user', $userId, \PDO::PARAM_INT);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function getAllByGuestEmail(string $email, int $limit = 100, ?int $ownerId = null, ?string $siteKey = null): array
    {
        $ownerSql = $ownerId !== null && $ownerId > 0 ? "AND id_owner = :id_owner" : "";
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE guest_email = :guest_email
              {$ownerSql}
              {$siteSql}
            ORDER BY id DESC
            LIMIT :limit
        ");
        $this->db->bind(':guest_email', $email);
        if ($ownerSql !== '') {
            $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        }
        $this->bindSiteScope($siteKey);
        $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

        return $this->db->fetchAll();
    }

    public function hasAnyPaidOrderForCustomer(int $ownerId, ?int $userId, ?string $email, ?string $siteKey = null): bool
    {
        $userId = $userId ? (int)$userId : 0;
        $email = strtolower(trim((string)$email));

        if ($userId <= 0 && $email === '') {
            return false;
        }

        $siteSql = $this->siteScopeSql($siteKey);
        $sql = "
            SELECT 1
            FROM {$this->table}
            WHERE id_owner = :id_owner
              AND payment_status = :payment_status
              {$siteSql}
              AND (
                (:id_user > 0 AND id_user = :id_user)
                OR (:guest_email <> '' AND LOWER(guest_email) = :guest_email)
              )
            LIMIT 1
        ";

        $this->db->query($sql);
        $this->db->bind(':id_owner', $ownerId, \PDO::PARAM_INT);
        $this->bindSiteScope($siteKey);
        $this->db->bind(':payment_status', self::PAYMENT_PAID);
        $this->db->bind(':id_user', $userId, \PDO::PARAM_INT);
        $this->db->bind(':guest_email', $email);

        return (bool)$this->db->fetchOne();
    }
}
