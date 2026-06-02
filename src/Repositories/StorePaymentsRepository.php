<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class StorePaymentsRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    const STATUS_PENDING = 'PENDING';
    const STATUS_PAID = 'PAID';
    const STATUS_FAILED = 'FAILED';
    const STATUS_REFUNDED = 'REFUNDED';

    const TYPE_FULL = 'FULL';
    const TYPE_SUBSCRIPTION_INITIAL = 'SUBSCRIPTION_INITIAL';
    const TYPE_RECOVERY = 'RECOVERY';

    protected array $fields = [
        'id',
        'id_owner',
        'site_key',
        'id_store_order',
        'id_user',
        'payment_method',
        'payment_type',
        'external_payment_id',
        'external_reference',
        'amount',
        'currency',
        'status',
        'payer_name',
        'payer_email',
        'raw_response',
        'paid_at',
        'created_at'
    ];

    public function __construct()
    {
        $this->table = "store_payments";
        $this->db = new Connection();
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getByOrder(int $orderId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
            ORDER BY id DESC
        ");
        $this->db->bind(':id_store_order', $orderId);

        return $this->db->fetchAll();
    }

    public function getSuccessfulByOrder(int $orderId): array
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
              AND status = :status
            ORDER BY id DESC
        ");
        $this->db->bind(':id_store_order', $orderId);
        $this->db->bind(':status', self::STATUS_PAID);

        return $this->db->fetchAll();
    }

    public function getLastSuccessfulByOrder(int $orderId): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
              AND status = :status
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':id_store_order', $orderId);
        $this->db->bind(':status', self::STATUS_PAID);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getByExternalPaymentId(string $externalPaymentId): ?object
    {
        $this->db->query("
            SELECT *
            FROM {$this->table}
            WHERE external_payment_id = :external_payment_id
            LIMIT 1
        ");
        $this->db->bind(':external_payment_id', $externalPaymentId);

        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getTotalPaidByOrder(int $orderId): float
    {
        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total_paid
            FROM {$this->table}
            WHERE id_store_order = :id_store_order
              AND status = :status
        ");
        $this->db->bind(':id_store_order', $orderId);
        $this->db->bind(':status', self::STATUS_PAID);

        $result = $this->db->fetchOne();
        return (float)($result->total_paid ?? 0);
    }

    public function markAsPaid(int $paymentId, ?string $externalPaymentId = null, ?string $rawResponse = null): bool
    {
        $data = [
            'status' => self::STATUS_PAID,
            'paid_at' => date('Y-m-d H:i:s')
        ];

        if ($externalPaymentId !== null) {
            $data['external_payment_id'] = $externalPaymentId;
        }

        if ($rawResponse !== null) {
            $data['raw_response'] = $rawResponse;
        }

        return $this->update($data, ['id' => $paymentId]);
    }

    public function markAsFailed(int $paymentId, ?string $rawResponse = null): bool
    {
        $data = [
            'status' => self::STATUS_FAILED
        ];

        if ($rawResponse !== null) {
            $data['raw_response'] = $rawResponse;
        }

        return $this->update($data, ['id' => $paymentId]);
    }

    public function markAsRefunded(int $paymentId, ?string $rawResponse = null): bool
    {
        $data = [
            'status' => self::STATUS_REFUNDED
        ];

        if ($rawResponse !== null) {
            $data['raw_response'] = $rawResponse;
        }

        return $this->update($data, ['id' => $paymentId]);
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
    $this->db->bind(':id_owner', $ownerId);
    $this->bindSiteScope($siteKey);
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll();
}

public function getFilteredByOwner(
    int $ownerId,
    ?string $paymentStatus = null,
    ?string $status = null,
    ?string $email = null,
    int $limit = 100
): array {
    $sql = "SELECT * FROM {$this->table} WHERE id_owner = :id_owner";
    $params = [
        ':id_owner' => $ownerId
    ];

    if ($paymentStatus !== null && $paymentStatus !== '') {
        $sql .= " AND payment_status = :payment_status";
        $params[':payment_status'] = $paymentStatus;
    }

    if ($status !== null && $status !== '') {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }

    if ($email !== null && $email !== '') {
        $sql .= " AND guest_email LIKE :guest_email";
        $params[':guest_email'] = '%' . $email . '%';
    }

    $sql .= " ORDER BY id DESC LIMIT :limit";

    $this->db->query($sql);

    foreach ($params as $key => $value) {
        $this->db->bind($key, $value);
    }
    $this->db->bind(':limit', $limit, \PDO::PARAM_INT);

    return $this->db->fetchAll();
}
}
