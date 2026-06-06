<?php

namespace App\Repositories;

class OrderServiceMenuSelectionsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = 'order_service_menu_selections';
    }

    /**
     * Get all selections for a service slot (main order or suborder)
     *
     * @param int $idOrder
     * @param int|null $idSuborder null for main order
     * @param int $idService
     * @param string $source 'main_order' or 'suborder'
     * @return array
     */
    public function getByService(int $idOrder, ?int $idSuborder, int $idService, string $source): array
    {
        try {
            $sql = "SELECT s.*, i.name AS item_name, i.price_info, c.name AS category_name 
                    FROM `{$this->table}` s 
                    INNER JOIN menu_category_items i ON i.id = s.id_menu_category_item 
                    INNER JOIN menu_categories c ON c.id = i.id_category 
                    WHERE s.id_order = :id_order AND s.id_service = :id_service AND s.source = :source";
            $params = [':id_order' => $idOrder, ':id_service' => $idService, ':source' => $source];
            if ($source === 'suborder' && $idSuborder !== null) {
                $sql .= " AND s.id_suborder = :id_suborder";
                $params[':id_suborder'] = $idSuborder;
            } else {
                $sql .= " AND s.id_suborder IS NULL";
            }
            $sql .= " ORDER BY c.display_order, i.display_order";
            $this->db->query($sql);
            foreach ($params as $k => $v) {
                $this->db->bind($k, $v);
            }
            return $this->db->fetchAll();
        } catch (\PDOException $e) {
            if ($this->showError) {
                error_log("OrderServiceMenuSelectionsRepository::getByService: " . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Delete all selections for a service slot and optionally add new ones
     *
     * @param int $idOrder
     * @param int|null $idSuborder
     * @param int $idService
     * @param string $source
     * @return bool
     */
    public function deleteForService(int $idOrder, ?int $idSuborder, int $idService, string $source): bool
    {
        try {
            $sql = "DELETE FROM `{$this->table}` WHERE id_order = :id_order AND id_service = :id_service AND source = :source";
            $params = [':id_order' => $idOrder, ':id_service' => $idService, ':source' => $source];
            if ($source === 'suborder' && $idSuborder !== null) {
                $sql .= " AND id_suborder = :id_suborder";
                $params[':id_suborder'] = $idSuborder;
            } else {
                $sql .= " AND id_suborder IS NULL";
            }
            $this->db->query($sql);
            foreach ($params as $k => $v) {
                $this->db->bind($k, $v);
            }
            $this->db->execute();
            return true;
        } catch (\PDOException $e) {
            if ($this->showError) {
                error_log("OrderServiceMenuSelectionsRepository::deleteForService: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Add a single selection (no duplicate check; caller should avoid duplicates)
     *
     * @param int $idOrder
     * @param int|null $idSuborder
     * @param int $idService
     * @param int $idMenuCategoryItem
     * @param string $source
     * @return bool
     */
    public function addSelection(
        int $idOrder,
        ?int $idSuborder,
        int $idService,
        int $idMenuCategoryItem,
        string $source
    ): bool {
        return $this->add([
            'id_order' => $idOrder,
            'id_suborder' => $idSuborder,
            'id_service' => $idService,
            'id_menu_category_item' => $idMenuCategoryItem,
            'source' => $source,
        ]);
    }
}
