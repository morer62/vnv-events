<?php

namespace App\Repositories;

class ServiceZipPaymentsRepository extends BaseRepository
{
    const PENDING = "PENDING";
    const APPROVED = "APPROVED";
    const REJECTED = "REJECTED";
    const SUSPENDED = "SUSPENDED";

    const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SUSPENDED];

    public function __construct()
    {
        $this->table = "payments_service_zip_codes";
        $this->db = new Connection();
    }

    public function getApprovedWithUserAndService(string $email = '', string $from = '', string $to = ''): array
        {
            $sql = "
                SELECT p.*, s.name, u.email, u.phone
                FROM {$this->table} p
                JOIN service s ON p.id_service = s.id
                JOIN users u ON s.user_id = u.id
                WHERE p.status = 'APPROVED'
            ";

            if ($email) {
                $sql .= " AND u.email LIKE :email";
            }

            if ($from && $to) {
                $sql .= " AND p.payment_date BETWEEN :from AND :to";
            }

            $this->db->query($sql);

            if ($email) $this->db->bind(':email', "%{$email}%");
            if ($from && $to) {
                $this->db->bind(':from', $from);
                $this->db->bind(':to', $to);
            }

            return $this->db->fetchAll();
        }

        public function getExpiredWithUserAndService(): array
        {
            $this->db->query("
                SELECT s.name, u.email, u.phone, p.id, c.token AS user_token
                FROM {$this->table} p
                JOIN service s ON p.id_service = s.id
                JOIN users u ON s.user_id = u.id
                JOIN user_cards c ON c.id_user = u.id AND c.main_card = 'yes'
                WHERE p.status = 'APPROVED'
                AND p.renewal < CURDATE()
            ");
            return $this->db->fetchAll();
        }
        

    public function getAllByUser(int $userId): array
    {
        $this->db->query("
            SELECT p.*
            FROM {$this->table} p
            JOIN service s ON s.id = p.id_service
            WHERE s.user_id = :user_id
            ORDER BY p.payment_date DESC
        ");
        $this->db->bind(":user_id", $userId);
        return $this->db->fetchAll();
    }


    public function getAllByService(int $serviceId): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_service = :id_service");
        $this->db->bind(":id_service", $serviceId);
        return $this->db->fetchAll();
    }
}
