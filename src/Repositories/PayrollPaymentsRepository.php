<?php

namespace App\Repositories;

class PayrollPaymentsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "payroll_payments";
        $this->db = new Connection();
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
