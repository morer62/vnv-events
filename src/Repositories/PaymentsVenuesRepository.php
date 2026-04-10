<?php

namespace App\Repositories;

class PaymentsVenuesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "payments_venues";
        $this->db = new Connection();
    }

    public function getApprovedWithUserAndVenue(string $email = '', string $from = '', string $to = ''): array
    {
        $sql = "
            SELECT p.*, v.name, u.email, u.phone
            FROM {$this->table} p
            JOIN venues v ON p.id_venues = v.id
            JOIN users u ON v.user_id = u.id
            WHERE p.active = 'yes'
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

    public function getExpiredWithUserAndVenue(): array
    {
        $this->db->query("
            SELECT p.*, u.name as user_name, u.email, u.phone, v.name as venue_name
            FROM {$this->table} p
            JOIN venues v ON v.id = p.id_venues
            JOIN users u ON u.id = v.user_id
            WHERE p.active = 'yes' AND p.renewal < CURDATE()
            ORDER BY p.renewal ASC
        ");

        return $this->db->fetchAll();
    }

     




   
    public function existsActivePayment(int $venueId): bool
    {
        $this->db->query("
            SELECT COUNT(*) as total 
            FROM {$this->table} 
            WHERE id_venues = :venue_id 
              AND active = 'yes' 
              AND renewal >= CURDATE()
        ");
        $this->db->bind(":venue_id", $venueId);
        $this->db->execute();

        $result = $this->db->fetchOne();
        return $result["total"] > 0;
    }
}
 