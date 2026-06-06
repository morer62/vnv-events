<?php

namespace App\Repositories;

class MenuCategoriesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->db = new Connection();
        $this->table = 'menu_categories';
    }

    /**
     * @return array All categories ordered by display_order
     */
    public function getAllOrdered(): array
    {
        try {
            $this->db->query("SELECT * FROM `{$this->table}` ORDER BY `display_order` ASC, `id` ASC");
            return $this->db->fetchAll();
        } catch (\PDOException $e) {
            if ($this->showError) {
                error_log("MenuCategoriesRepository::getAllOrdered: " . $e->getMessage());
            }
            return [];
        }
    }
}
