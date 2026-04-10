<?php

namespace App\Repositories;

use PDOException;

class OrderSuborderServicesAssignedRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "order_suborder_services_assigned";
        $this->db = new Connection();
    }

    public function add(array $data): bool
    {
        $this->db->query("
            INSERT INTO {$this->table} (
                id_suborder, 
                id_service, 
                quantity, 
                unit_price,
                description,
                subtotal, 
                id_owner, 
                is_variable, 
                variable_price
            ) VALUES (
                :id_suborder, 
                :id_service, 
                :quantity, 
                :unit_price,
                :description,
                :subtotal, 
                :id_owner, 
                :is_variable, 
                :variable_price
            )
        ");

        $this->db->bind(':id_suborder', $data['id_suborder']);
        $this->db->bind(':id_service', $data['id_service']);
        $this->db->bind(':quantity', $data['quantity']);
        $this->db->bind(':unit_price', $data['unit_price'] ?? 0);
        $this->db->bind(':description', $data['description'] ?? null);
        $this->db->bind(':subtotal', $data['subtotal']);
        $this->db->bind(':id_owner', $data['id_owner']);
        $this->db->bind(':is_variable', $data['is_variable']);
        $this->db->bind(':variable_price', $data['variable_price']);

        try {
            $this->db->execute();
            return true;
        } catch (PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function getAllBy(array $criteriaVals, array $columns = [], int $limit = 0): array
    {
        try {
            $columnsSQL = count($columns) > 0 ? implode(',', $columns) : '*';
            $criteria = $criteriaVals;

            $keys = array_keys($criteria);
            $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));
            $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where";

            if ($limit > 0) {
                $query .= " LIMIT $limit";
            }

            $this->db->query($query);
            foreach ($criteria as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            return $this->db->fetchAll();
        } catch (PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return [];
        }
    }

    public function delete(array $keys): bool
    {
        try {
            $keys2 = array_keys($keys);
            $this->db->query("DELETE FROM `$this->table` WHERE `$keys2[0]` = :data ;");
            $this->db->bind(":data", $keys[$keys2[0]]);

            $this->db->execute();
            return true;
        } catch (PDOException $e) {
            if ($this->showError) {
                echo $e->getMessage();
            }
            return false;
        }
    }

    public function getServicesWithDetails($suborderId)
    {
        $this->db->query("
            SELECT 
                ssa.*,
                s.name as service_name,
                CASE 
                    WHEN ssa.description IS NOT NULL AND ssa.description != ''
                    THEN ssa.description
                    ELSE s.description
                END as service_description,
                s.price as service_price,
                CASE 
                    WHEN ssa.unit_price IS NOT NULL AND ssa.unit_price > 0
                    THEN ssa.unit_price
                    WHEN ssa.is_variable = 'YES' AND ssa.variable_price IS NOT NULL 
                    THEN ssa.variable_price 
                    ELSE s.price 
                END as actual_price
            FROM {$this->table} ssa
            LEFT JOIN orders_services s ON s.id = ssa.id_service
            WHERE ssa.id_suborder = :id_suborder
        ");

        $this->db->bind(':id_suborder', $suborderId);
        $this->db->execute();
        return $this->db->fetchAll();
    }
}
