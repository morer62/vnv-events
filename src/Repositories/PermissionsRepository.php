<?php

namespace App\Repositories;

class PermissionsRepository extends BaseRepository
{
    protected array $fields = ['module', 'action', 'label'];

    public function __construct()
    {
        $this->table = "permissions";
        $this->db = new Connection();
    }

    public function getAllOrdered(): array
    {
        try {
            $this->db->query("SELECT * FROM `{$this->table}` ORDER BY `module` ASC, `action` ASC");
            return $this->db->fetchAll();
        } catch (\PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return [];
        }
    }
}
 
