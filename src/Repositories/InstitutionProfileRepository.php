<?php

namespace App\Repositories;

class InstitutionProfileRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "institution_profile";
        $this->db = new Connection();
    }

public function getByOwner(int $id): ?object
{
    $this->db->query("SELECT *, 
        CASE 
            WHEN logo_path LIKE 'http%' THEN logo_path
            WHEN logo_path LIKE '%/v%' THEN logo_path 
            ELSE CONCAT(:base_url, logo_path) 
        END as logo_path
        FROM {$this->table} WHERE id_owner = :id LIMIT 1");
    
    $this->db->bind(":id", $id);
    $this->db->bind(":base_url", $_ENV['APP_URL']);
    
    $result = $this->db->fetchOne();
    return $result ?: null;
}

public function getById(int $id): ?object
{
    $this->db->query("SELECT *, 
        CASE 
            WHEN logo_path LIKE 'http%' THEN logo_path
            WHEN logo_path LIKE '%/v%' THEN logo_path 
            ELSE CONCAT(:base_url, logo_path) 
        END as logo_path
        FROM {$this->table} WHERE id = :id LIMIT 1");
    
    $this->db->bind(":id", $id);
    $this->db->bind(":base_url", $_ENV['APP_URL']);
    
    $result = $this->db->fetchOne();
    return $result ?: null;
}

public function getOwnerId(int $institutionId): ?int
{
    $this->db->query("SELECT id_owner FROM {$this->table} WHERE id = :id LIMIT 1");
    $this->db->bind(":id", $institutionId);
    
    $result = $this->db->fetchOne();
    return $result ? (int)$result->id_owner : null;
}

    
}
