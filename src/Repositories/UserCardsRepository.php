<?php

namespace App\Repositories;
 

class UserCardsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "user_cards";
        $this->db = new Connection();
        $this->ensureAddressColumns();
    }

    private function ensureAddressColumns(): void
    {
        $columns = [
            'billing_zip' => 'VARCHAR(60) NULL',
            'billing_address_1' => 'VARCHAR(255) NULL',
            'billing_address_2' => 'VARCHAR(255) NULL',
            'billing_city' => 'VARCHAR(120) NULL',
            'billing_state' => 'VARCHAR(120) NULL'
        ];

        foreach ($columns as $column => $definition) {
            if ($this->columnExists($column)) {
                continue;
            }
            try {
                $this->db->query("ALTER TABLE `{$this->table}` ADD COLUMN `{$column}` {$definition}");
                $this->db->execute();
            } catch (\Throwable $e) {
            }
        }
    }

    private function columnExists(string $column): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE :column");
            $this->db->bind(':column', $column);
            return (bool)$this->db->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getByUserId(int $userId): array
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_user = :id_user");
        $this->db->bind(":id_user", $userId);
        return $this->db->fetchAll();
    }

    public function getMainCardByUserId(int $userId): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_user = :id_user AND main_card = 'yes' LIMIT 1");
        $this->db->bind(":id_user", $userId);
        $result = $this->db->fetchOne();
        // fetchOne() puede devolver false cuando no hay resultados, convertir a null
        return ($result === false) ? null : $result;
    }

    public function deleteCard(int $cardId): void
    {
        $this->db->query("DELETE FROM user_cards WHERE id = :id");
        $this->db->bind(":id", $cardId);
        $this->db->execute();
    }

    public function countCards(int $userId): int
    {
        $this->db->query("SELECT * FROM user_cards WHERE id_user = :id_user");
        $this->db->bind(":id_user", $userId);
        return $this->db->count();
    }

    public function setMainCard(int $userId, int $cardId): void
    {
        $this->db->query("UPDATE {$this->table} SET main_card = 'no' WHERE id_user = :id_user");
        $this->db->bind(":id_user", $userId);
        $this->db->execute();

        $this->db->query("UPDATE {$this->table} SET main_card = 'yes' WHERE id = :id AND id_user = :id_user");
        $this->db->bind(":id", $cardId);
        $this->db->bind(":id_user", $userId);
        $this->db->execute();
    }
}
