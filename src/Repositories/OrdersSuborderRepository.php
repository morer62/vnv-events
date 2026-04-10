<?php

namespace App\Repositories;

use PDOException;

class OrdersSuborderRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "orders_suborder";
        $this->db = new Connection();
    }

    public function createSuborder($orderId, $data)
    {
        $this->db->query("
            INSERT INTO {$this->table} (
                id_order, 
                tax_percertance, 
                payment_split_type, 
                payment_split_percent_1, 
                payment_split_percent_2, 
                discount_type,
                discount_value,
                status_workflow,
                is_archived
            ) VALUES (
                :id_order, 
                :tax_percertance, 
                :payment_split_type, 
                :payment_split_percent_1, 
                :payment_split_percent_2, 
                :discount_type,
                :discount_value,
                :status_workflow,
                0
            )
        ");

        $this->db->bind(':id_order', $orderId);
        $this->db->bind(':tax_percertance', $data['tax_percentage']);
        $this->db->bind(':payment_split_type', $data['payment_split_type']);
        $this->db->bind(':payment_split_percent_1', $data['payment_split_percent_1']);
        $this->db->bind(':payment_split_percent_2', $data['payment_split_percent_2']);
        $this->db->bind(':discount_type', $data['discount_type'] ?? 'amount');
        $this->db->bind(':discount_value', $data['discount_value'] ?? 0);
        // Status por defecto: INVOICE_READY (Signed – Payment Pending) ya que el contrato padre ya está firmado
        $this->db->bind(':status_workflow', 'INVOICE_READY');

        $this->db->execute();
        return $this->db->lastId();
    }

    public function getByOrder($orderId)
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE id_order = :id_order AND is_archived = 0");
        $this->db->bind(':id_order', $orderId);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function getOne(array $criteriaVals, array $columns = []): ?object
    {
        try {
            $columnsSQL = count($columns) > 0 ? implode(',', $columns) : '*';
            $criteria = $criteriaVals;

            $keys = array_keys($criteria);
            $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));
            $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where";

            $this->db->query($query);
            foreach ($criteria as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            $result = $this->db->fetchOne();
            return !$result ? null : $result;
        } catch (PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return null;
        }
    }

    public function update(array $data, array $criteriaVals): bool
    {
        try {
            $keys = array_keys($data);
            $keysCriteria = array_keys($criteriaVals);
            $update = "";
            $criteria = "";

            for ($i = 0; $i < count($keys); $i++) {
                $update .= " `$keys[$i]` = :$keys[$i] ";

                if ($i != count($keys) - 1) {
                    $update .= ", ";
                }
            }

            for ($i = 0; $i < count($keysCriteria); $i++) {
                $criteria .= " `$keysCriteria[$i]` = :$keysCriteria[$i] ";

                if ($i != count($keysCriteria) - 1) {
                    $criteria .= " AND ";
                }
            }

            $query = "UPDATE `$this->table` SET $update WHERE $criteria;";

            $this->db->query($query);
            for ($i = 0; $i < count($keys); $i++) {
                $this->db->bind(":$keys[$i]", $data[$keys[$i]]);
            }

            for ($i = 0; $i < count($keysCriteria); $i++) {
                $this->db->bind(":$keysCriteria[$i]", $criteriaVals[$keysCriteria[$i]]);
            }

            $this->db->execute();

            return true;
        } catch (PDOException $th) {
            if ($this->showError) {
                echo $th->getMessage();
            }
            return false;
        }
    }

    public function getByIdWithoutOwnershipCheck(int $id): ?object
    {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $this->db->query($query);
        $this->db->bind(":id", $id);
        return $this->db->fetchOne();
    }
}
