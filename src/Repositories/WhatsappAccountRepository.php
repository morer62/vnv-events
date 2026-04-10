<?php

namespace App\Repositories;

class WhatsappAccountRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "whatsapp_account";
        $this->db = new Connection();
    }

    public function setActive(int $id): void
    {
        // Desactiva todos
        $this->db->query("UPDATE {$this->table} SET is_active = 0");
        $this->db->execute();

        // Activa el seleccionado
        $this->db->query("UPDATE {$this->table} SET is_active = 1 WHERE id = :id");
        $this->db->bind(":id", $id);
        $this->db->execute(); // ← ESTA LÍNEA ES CLAVE
    }


    public function getActive()
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE is_active = 1 LIMIT 1");
        return $this->db->fetchOne();
    }

}
