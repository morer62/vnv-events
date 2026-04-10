<?php

namespace App\Repositories;

class MembershipPaymentsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "membership_payments";
        $this->db = new Connection();
    }

    public function getAllByUserId(int $userId): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_user = :user_id ORDER BY payment_date DESC");
        $this->db->bind(":user_id", $userId);
        return $this->db->fetchAll();
    }

    

    public function add(array $data): bool
    {
        $columns = array_keys($data);
        $fields = implode(", ", $columns);
        $placeholders = implode(", ", array_map(fn($c) => ":$c", $columns));

        $this->db->query("INSERT INTO {$this->table} ($fields) VALUES ($placeholders)");

        foreach ($data as $key => $value) {
            $this->db->bind(":$key", $value);
        }

        $this->db->execute();

        return true;
    }

}
