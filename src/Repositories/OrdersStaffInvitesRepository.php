<?php

namespace App\Repositories;

class OrdersStaffInvitesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_staff_invites";
        $this->db = new Connection();
    }

    public function getInvitesByOrder(int $id_order): array
    {
        $this->db->query("
            SELECT 
                osi.*, 
                u.name, 
                u.email
            FROM {$this->table} osi
            JOIN users u ON osi.id_user = u.id
            WHERE osi.id_order = :id_order
            ORDER BY osi.created_at DESC
        ");
        $this->db->bind(":id_order", $id_order);
        return $this->db->fetchAll();
    }

    public function getInvite(int $id_order, int $id_user): object|bool
    {
        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE id_order = :id_order AND id_user = :id_user
            LIMIT 1
        ");
        $this->db->bind(":id_order", $id_order);
        $this->db->bind(":id_user", $id_user);
        return $this->db->fetchOne();
    }

    public function setConfirmation(int $id_order, int $id_user, bool $accepted): void
    {
        $this->db->query("
            UPDATE {$this->table}
            SET is_confirmed = :accepted, confirmed_at = NOW()
            WHERE id_order = :id_order AND id_user = :id_user
        ");
        $this->db->bind(":accepted", $accepted ? 1 : 0);
        $this->db->bind(":id_order", $id_order);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }

    public function inviteUser(int $id_order, int $id_user): void
    {
        $this->db->query("
            INSERT IGNORE INTO {$this->table} (id_order, id_user)
            VALUES (:id_order, :id_user)
        ");
        $this->db->bind(":id_order", $id_order);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }

    public function removeInvite(int $id_order, int $id_user): void
    {
        $this->db->query("
            DELETE FROM {$this->table}
            WHERE id_order = :id_order AND id_user = :id_user
        ");
        $this->db->bind(":id_order", $id_order);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }

    public function confirmInvitation(int $id_order, int $id_user, int $is_confirmed): void
    {
        $this->db->query("
            UPDATE {$this->table}
            SET is_confirmed = :is_confirmed,
                confirmed_at = NOW()
            WHERE id_order = :id_order AND id_user = :id_user
        ");
        $this->db->bind(":is_confirmed", $is_confirmed);
        $this->db->bind(":id_order", $id_order);
        $this->db->bind(":id_user", $id_user);
        $this->db->execute();
    }
}
