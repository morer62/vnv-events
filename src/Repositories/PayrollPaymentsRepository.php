<?php

namespace App\Repositories;

class PayrollPaymentsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "payroll_payments";
        $this->db = new Connection();
    }

    /**
     * Records one payroll payment and marks exactly the selected sessions as paid.
     * The lock and row-count checks prevent a partial payment, duplicate payment,
     * or notification containing sessions that were not actually paid.
     *
     * @return object[] The sessions confirmed as paid by this transaction.
     */
    public function recordSelectedPayment(
        int $userId,
        int $ownerId,
        array $hourIds,
        string $proofUrl,
        mixed $hourlyRate,
        string $additionalInfo,
        string $paidAt
    ): array {
        $hourIds = array_values(array_unique(array_filter(array_map('intval', $hourIds), static fn (int $id): bool => $id > 0)));
        if ($userId <= 0 || $ownerId <= 0 || $hourIds === []) {
            throw new \InvalidArgumentException('No valid payroll sessions were selected.');
        }

        $placeholders = [];
        foreach ($hourIds as $index => $id) {
            $placeholders[] = ':hour_' . $index;
        }

        $this->db->beginTransaction();
        try {
            $this->db->query(
                'SELECT id, start_time, end_time FROM payroll_hours '
                . 'WHERE id_user = :user AND id_owner = :owner AND is_paid = 0 '
                . 'AND start_time IS NOT NULL AND end_time IS NOT NULL '
                . 'AND end_time > start_time AND id IN (' . implode(',', $placeholders) . ') '
                . 'ORDER BY start_time ASC FOR UPDATE'
            );
            $this->db->bind(':user', $userId);
            $this->db->bind(':owner', $ownerId);
            foreach ($hourIds as $index => $id) {
                $this->db->bind(':hour_' . $index, $id);
            }
            $paidHours = $this->db->fetchAll();

            if (count($paidHours) !== count($hourIds)) {
                throw new \RuntimeException('One or more selected sessions are no longer pending. Refresh and try again.');
            }

            $confirmedIds = array_map(static fn (object $hour): int => (int)$hour->id, $paidHours);
            $this->db->query(
                'INSERT INTO payroll_payments '
                . '(id_user, id_owner, hours_count, hours_ids, proof_url, paid_at, hourly_rate_snapshot, aditional_info) '
                . 'VALUES (:user, :owner, :count, :ids, :proof, :paid_at, :rate, :info)'
            );
            $this->db->bind(':user', $userId);
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':count', count($confirmedIds));
            $this->db->bind(':ids', json_encode($confirmedIds));
            $this->db->bind(':proof', $proofUrl);
            $this->db->bind(':paid_at', $paidAt);
            $this->db->bind(':rate', $hourlyRate);
            $this->db->bind(':info', $additionalInfo);
            $this->db->execute();

            $updatePlaceholders = [];
            foreach ($confirmedIds as $index => $id) {
                $updatePlaceholders[] = ':paid_hour_' . $index;
            }
            $this->db->query(
                'UPDATE payroll_hours SET is_paid = 1, paid_at = :paid_at, proof_url = :proof '
                . 'WHERE id_user = :user AND id_owner = :owner AND is_paid = 0 '
                . 'AND id IN (' . implode(',', $updatePlaceholders) . ')'
            );
            $this->db->bind(':paid_at', $paidAt);
            $this->db->bind(':proof', $proofUrl);
            $this->db->bind(':user', $userId);
            $this->db->bind(':owner', $ownerId);
            foreach ($confirmedIds as $index => $id) {
                $this->db->bind(':paid_hour_' . $index, $id);
            }
            $this->db->execute();
            if ($this->db->rowCount() !== count($confirmedIds)) {
                throw new \RuntimeException('The selected payroll sessions could not all be marked as paid.');
            }

            $this->db->commit();
            return $paidHours;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function getFiltered(?string $userId = null, ?string $from = null, ?string $to = null): array
    {
        $conditions = [];
        $params = [];

        if ($userId) {
            $conditions[] = "id_user = :id_user";
            $params[':id_user'] = $userId;
        }

        if ($from) {
            $conditions[] = "paid_at >= :from";
            $params[':from'] = $from;
        }

        if ($to) {
            $conditions[] = "paid_at <= :to";
            $params[':to'] = $to;
        }

        $sql = "SELECT * FROM {$this->table}";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY paid_at DESC";

        $this->db->query($sql);
        foreach ($params as $key => $val) {
            $this->db->bind($key, $val);
        }

        return $this->db->fetchAll();
    }

    public function getHoursForPayment(int $paymentId): array
{
    // Step 1: Get the hours_ids list from payroll_payments
    $sql = "SELECT hours_ids FROM payroll_payments WHERE id = :id";
    $this->db->query($sql);
    $this->db->bind(":id", $paymentId);
    $payment = $this->db->fetchAll()[0] ?? null;

    if (!$payment || empty($payment->hours_ids)) {
        return [];
    }

    // Step 2: Clean and explode the list of IDs
    $ids = explode(",", str_replace(["[", "]", " "], "", $payment->hours_ids));
    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    // Step 3: Get each hour using IN clause
    $sql = "
        SELECT h.*, u.email
        FROM payroll_hours h
        JOIN users u ON u.id = h.id_user
        WHERE h.id IN ($placeholders)
        ORDER BY h.start_time ASC
    ";

    $this->db->query($sql);
    foreach ($ids as $i => $id) {
        $this->db->bind(($i + 1), $id); // bind by position
    }

    return $this->db->fetchAll();
}


    public function getGroupedPayments(int $idOwner, ?string $userId = null, ?string $from = null, ?string $to = null): array
    {
        $conditions = ["pp.id_owner = :id_owner"];
        $params = [":id_owner" => $idOwner];

        if ($userId) {
            $conditions[] = "pp.id_user = :id_user";
            $params[":id_user"] = $userId;
        }

        if ($from) {
            $conditions[] = "pp.paid_at >= :from";
            $params[":from"] = $from;
        }

        if ($to) {
            $conditions[] = "pp.paid_at <= :to";
            $params[":to"] = $to;
        }

        $sql = "
            SELECT 
                pp.*,
                u.email
            FROM payroll_payments pp
            LEFT JOIN users u ON pp.id_user = u.id
        ";

        if ($conditions) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY pp.paid_at DESC";

        $this->db->query($sql);
        foreach ($params as $key => $val) {
            $this->db->bind($key, $val);
        }

        return $this->db->fetchAll();
    }

    public function getAllPaymentsByUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $conditions = ["pp.id_user = :id_user"];
        $params = [":id_user" => $userId];

        if ($from) {
            $conditions[] = "pp.paid_at >= :from";
            $params[":from"] = $from;
        }

        if ($to) {
            $conditions[] = "pp.paid_at <= :to";
            $params[":to"] = $to;
        }

        $sql = "
            SELECT 
                pp.*,
                u.email,
                i.company_name as institution_name
            FROM payroll_payments pp
            LEFT JOIN users u ON pp.id_user = u.id
            LEFT JOIN institution_profile i ON i.id_owner = pp.id_owner
        ";

        if ($conditions) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY pp.paid_at DESC";

        $this->db->query($sql);
        foreach ($params as $key => $val) {
            $this->db->bind($key, $val);
        }

        return $this->db->fetchAll();
    }
}
