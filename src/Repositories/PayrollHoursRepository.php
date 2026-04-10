<?php

namespace App\Repositories;

use PDO;

class PayrollHoursRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "payroll_hours";
        $this->db = new Connection();
    }

    public function getHistoryWithUserAndStatus($from = "", $to = "", int|string $user = null, $is_paid = false, $idOwner = null): array
{
    $dateCriteria = "";
    $userCriteria = "";

    if ($from && $to) {
        $dateCriteria = "AND ph.start_time BETWEEN :from AND :to";
    }

    if ($user) {
        $userCriteria = "AND ph.id_user = :user";
    }

    $sql = "
        SELECT 
            ph.*,
            u.email,
            pp.id AS id_payment,
            pp.paid_at,
            pp.proof_url
        FROM payroll_hours ph
        INNER JOIN users u ON u.id = ph.id_user
        LEFT JOIN payroll_payments pp ON FIND_IN_SET(ph.id, pp.hours_ids) > 0
        WHERE ph.is_paid = :is_paid
            AND ph.end_time IS NOT NULL
            AND ph.id_owner = :idOwner
            {$dateCriteria}
            {$userCriteria}
    ";

    $this->db->query($sql);
    $this->db->bind(":is_paid", $is_paid, PDO::PARAM_BOOL);
    $this->db->bind(":idOwner", $idOwner);

    if ($from && $to) {
        $this->db->bind(":from", $from);
        $this->db->bind(":to", $to);
    }

    if ($user) {
        $this->db->bind(":user", intval($user));
    }

    return $this->db->fetchAll();
}


    public function getUnpaidByUser(int $userId, int $ownerId): array {
        $sql = "
            SELECT h.*, u.email 
            FROM payroll_hours h
            JOIN users u ON u.id = h.id_user
            WHERE h.id_user = :user_id 
            AND h.id_owner = :owner_id 
            AND h.is_paid = 0
            ORDER BY h.start_time ASC
        ";
    
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":owner_id", $ownerId);
    
        return $this->db->fetchAll();
    }

    public function getAllUnpaidByUser(int $userId, string $from = "", string $to = ""): array {
        $dateCriteria = "";
        
        if ($from && $to) {
            $dateCriteria = "AND h.start_time BETWEEN :from AND :to";
        }

        $sql = "
            SELECT 
                h.*, 
                u.email,
                i.company_name as institution_name,
                pp.id AS id_payment,
                pp.paid_at,
                pp.proof_url
            FROM payroll_hours h
            JOIN users u ON u.id = h.id_user
            LEFT JOIN institution_profile i ON i.id_owner = h.id_owner
            LEFT JOIN payroll_payments pp ON FIND_IN_SET(h.id, pp.hours_ids) > 0
            WHERE h.id_user = :user_id 
            AND h.is_paid = 0
            AND h.end_time IS NOT NULL
            {$dateCriteria}
            ORDER BY h.start_time DESC
        ";
    
        $this->db->query($sql);
        $this->db->bind(":user_id", $userId);
        
        if ($from && $to) {
            $this->db->bind(":from", $from);
            $this->db->bind(":to", $to);
        }
    
        return $this->db->fetchAll();
    }
    

    public function getFiltered(?string $userId = null, ?string $from = null, ?string $to = null): array
    {
        $conditions = [];
        $params = [];

        if ($userId) {
            $conditions[] = "id_user = :id_user";
            $params[":id_user"] = $userId;
        }

        if ($from) {
            $conditions[] = "start_time >= :from";
            $params[":from"] = $from;
        }

        if ($to) {
            $conditions[] = "end_time <= :to";
            $params[":to"] = $to;
        }

        $sql = "SELECT * FROM {$this->table}";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY start_time DESC";

        $this->db->query($sql);
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return $this->db->fetchAll();
    }

    // src/Repositories/PayrollHoursRepository.php

    public function createManualHour(int $userId, int $ownerId, string $startTime, string $endTime, ?string $notes = null): bool
    {
        return $this->add([
            "id_user" => $userId,
            "id_owner" => $ownerId,
            "start_time" => $startTime,
            "end_time" => $endTime,
            "notes" => $notes,
            "is_paid" => 0
        ]);
    }

    public function getByIdWithoutOwnership(int $id): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $this->db->bind(":id", $id);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function deleteHourById(int $hourId): bool
    {
        $this->db->query("
            DELETE FROM {$this->table}
            WHERE id = :id
        ");
        $this->db->bind(":id", $hourId);

        return (bool)$this->db->execute();
    }

}