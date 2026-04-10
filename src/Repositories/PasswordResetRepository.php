<?php

namespace App\Repositories;

class PasswordResetRepository extends BaseRepository
{

    public function __construct()
    {
        $this->table = "password_resets";
        $this->db = new Connection();
    }

    public function isValid(string $token): bool
    {
        $this->db->query("SELECT id FROM $this->table WHERE token = :token AND expires_at > NOW() LIMIT 1");
        $this->db->bind(":token", $token);
        return $this->db->fetchOne() !== false;
    }
}
