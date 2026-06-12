<?php

namespace App\Repositories;

class AuthorizedManualChargeLogsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = 'authorized_manual_charge_logs';
        $this->db = new Connection();
    }

    public function findByIdempotencyKey(string $key): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE idempotency_key = :key AND status = 'SUCCESS' LIMIT 1");
        $this->db->bind(':key', $key);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }
}
