<?php

namespace App\Repositories;

class ForumCategoryRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "forum_categories";
        $this->db = new Connection();
    }

    public function getActiveCategories(): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE is_active = 1 
            ORDER BY order_index ASC, name ASC
        ");
        return $this->db->fetchAll();
    }

    public function getCategoryWithStats(int $id): object|bool
    {
        $this->db->query("
            SELECT 
                c.*,
                COUNT(DISTINCT t.id) as topics_count,
                COUNT(DISTINCT r.id) as replies_count,
                MAX(t.last_reply_at) as last_activity
            FROM {$this->table} c
            LEFT JOIN forum_topics t ON t.id_category = c.id AND t.is_approved = 1
            LEFT JOIN forum_replies r ON r.id_topic = t.id AND r.is_approved = 1
            WHERE c.id = :id
            GROUP BY c.id
        ");
        $this->db->bind(':id', $id);
        return $this->db->fetchOne();
    }

    public function getAllWithStats(): array
    {
        $this->db->query("
            SELECT 
                c.*,
                COUNT(DISTINCT t.id) as topics_count,
                COUNT(DISTINCT r.id) as replies_count
            FROM {$this->table} c
            LEFT JOIN forum_topics t ON t.id_category = c.id AND t.is_approved = 1
            LEFT JOIN forum_replies r ON r.id_topic = t.id AND r.is_approved = 1
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.order_index ASC, c.name ASC
        ");
        return $this->db->fetchAll();
    }
}





