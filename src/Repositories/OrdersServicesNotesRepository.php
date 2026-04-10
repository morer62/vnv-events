<?php

namespace App\Repositories;

class OrdersServicesNotesRepository extends BaseRepository
{ 

    public function __construct()
    {
        $this->table = 'orders_services_notes';
        $this->db = new Connection();
    }
 

    public function findByOrderAndService($id_order, $id_service)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id_order = ? AND id_service = ? LIMIT 1";
        $this->db->query($sql);
        $this->db->bind(1, $id_order);
        $this->db->bind(2, $id_service);
        return $this->db->fetchOne();
    }

    public function findBySuborderAndService($id_suborder, $id_service)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id_suborder = ? AND id_service = ? LIMIT 1";
        $this->db->query($sql);
        $this->db->bind(1, $id_suborder);
        $this->db->bind(2, $id_service);
        return $this->db->fetchOne();
    }

    public function findByAssignedId($id_assigned, $isSuborder = false)
    {
        $prefix = $isSuborder ? "SUBASSIGN:" : "MAINASSIGN:";
        $sql = "SELECT * FROM {$this->table} WHERE notes LIKE ? LIMIT 1";
        $this->db->query($sql);
        $this->db->bind(1, $prefix . $id_assigned . ":%");
        $result = $this->db->fetchOne();
        
        if ($result) {
            $notes = $result->notes ?? '';
            if (strpos($notes, $prefix . $id_assigned . ":") === 0) {
                $result->notes = substr($notes, strlen($prefix . $id_assigned . ":"));
            }
        }
        
        return $result;
    }
    
    private function encodeNotesWithAssignedId($notes, $id_assigned, $isSuborder = false)
    {
        $prefix = $isSuborder ? "SUBASSIGN:" : "MAINASSIGN:";
        return $prefix . $id_assigned . ":" . $notes;
    }
    
    public function addWithAssignedId(array $data, $id_assigned, $isSuborder = false)
    {
        if (isset($data['notes'])) {
            $data['notes'] = $this->encodeNotesWithAssignedId($data['notes'], $id_assigned, $isSuborder);
        }
        return $this->add($data);
    }
    
    public function updateWithAssignedId(array $data, $id_assigned, $isSuborder = false)
    {
        if (isset($data['notes'])) {
            $data['notes'] = $this->encodeNotesWithAssignedId($data['notes'], $id_assigned, $isSuborder);
        }
        
        $prefix = $isSuborder ? "SUBASSIGN:" : "MAINASSIGN:";
        $sql = "SELECT id FROM {$this->table} WHERE notes LIKE ? LIMIT 1";
        $this->db->query($sql);
        $this->db->bind(1, $prefix . $id_assigned . ":%");
        $existing = $this->db->fetchOne();
        
        if ($existing) {
            return $this->update($data, ["id" => $existing->id]);
        }
        return false;
    }
}
