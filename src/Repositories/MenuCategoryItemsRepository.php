<?php

namespace App\Repositories;

class MenuCategoryItemsRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = 'menu_category_items';
    }

    /**
     * @param int $idCategory
     * @return array Items for the category ordered by display_order
     */
    public function getByCategoryId(int $idCategory): array
    {
        try {
            $this->db->query(
                "SELECT * FROM `{$this->table}` WHERE `id_category` = :id_category ORDER BY `display_order` ASC, `id` ASC"
            );
            $this->db->bind(':id_category', $idCategory);
            return $this->db->fetchAll();
        } catch (\PDOException $e) {
            if ($this->showError) {
                error_log("MenuCategoryItemsRepository::getByCategoryId: " . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Get all items grouped by category id (for passing to Twig with all items in one payload)
     *
     * @return array [ id_category => [ item1, item2, ... ], ... ]
     */
    public function getAllGroupedByCategory(): array
    {
        try {
            $this->db->query(
                "SELECT * FROM `{$this->table}` ORDER BY `id_category` ASC, `display_order` ASC, `id` ASC"
            );
            $rows = $this->db->fetchAll();
            $grouped = [];
            foreach ($rows as $row) {
                $idCat = (int) $row->id_category;
                if (!isset($grouped[$idCat])) {
                    $grouped[$idCat] = [];
                }
                $grouped[$idCat][] = $row;
            }
            return $grouped;
        } catch (\PDOException $e) {
            if ($this->showError) {
                error_log("MenuCategoryItemsRepository::getAllGroupedByCategory: " . $e->getMessage());
            }
            return [];
        }
    }
}
