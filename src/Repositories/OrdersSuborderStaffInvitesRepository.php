<?php

namespace App\Repositories;

class OrdersSuborderStaffInvitesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_suborder_staff_invites";
        $this->db = new Connection();
    }

    public function getInvitesBySuborder(int $id_suborder): array
    {
        $this->db->query("
            SELECT 
                ossi.*, 
                u.name, 
                u.email
            FROM {$this->table} ossi
            JOIN users u ON ossi.id_user = u.id
            WHERE ossi.id_suborder = :id_suborder
            ORDER BY ossi.created_at DESC
        ");
        $this->db->bind(":id_suborder", $id_suborder);
        return $this->db->fetchAll();
    }

    public function getInvitesByUser(int $id_user): array
    {
        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE id_user = :id_user
            ORDER BY created_at DESC
        ");
        $this->db->bind(":id_user", $id_user);
        return $this->db->fetchAll();
    }

    public function getInvite(int $id_suborder, int $id_user): object|bool
    {
        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE id_suborder = :id_suborder AND id_user = :id_user
            LIMIT 1
        ");
        $this->db->bind(":id_suborder", $id_suborder);
        $this->db->bind(":id_user", $id_user);
        return $this->db->fetchOne();
    }

    public function setConfirmation(int $id_suborder, int $id_user, bool $accepted): void
    {
        $this->db->query("
            UPDATE {$this->table}
            SET is_confirmed = :accepted, confirmed_at = NOW()
            WHERE id_suborder = :id_suborder AND id_user = :id_user
        ");
        $this->db->bind(":accepted", $accepted ? 1 : 0);
        $this->db->bind(":id_suborder", $id_suborder);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }

    public function inviteUser(int $id_suborder, int $id_user): void
    {
        $this->db->query("
            INSERT IGNORE INTO {$this->table} (id_suborder, id_user)
            VALUES (:id_suborder, :id_user)
        ");
        $this->db->bind(":id_suborder", $id_suborder);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }

    public function removeInvite(int $id_suborder, int $id_user): void
    {
        $this->db->query("
            DELETE FROM {$this->table}
            WHERE id_suborder = :id_suborder AND id_user = :id_user
        ");
        $this->db->bind(":id_suborder", $id_suborder);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }

    public function confirmInvitation(int $id_suborder, int $id_user, int $is_confirmed): void
    {
        $this->db->query("
            UPDATE {$this->table}
            SET is_confirmed = :is_confirmed,
                confirmed_at = NOW()
            WHERE id_suborder = :id_suborder AND id_user = :id_user
        ");
        $this->db->bind(":is_confirmed", $is_confirmed);
        $this->db->bind(":id_suborder", $id_suborder);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }
}
