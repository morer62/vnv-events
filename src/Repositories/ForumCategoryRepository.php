<?php

namespace App\Repositories;

use App\Repositories\Concerns\SiteScopedRepositoryTrait;

class ForumCategoryRepository extends BaseRepository
{
    use SiteScopedRepositoryTrait;

    public function __construct()
    {
        $this->table = "forum_categories";
        $this->db = new Connection();
    }

    public function add(array $data): bool
    {
        return parent::add($this->withDefaultSiteKey($data));
    }

    public function getActiveCategories(?string $siteKey = null): array
    {
        $siteSql = $this->siteScopeSql($siteKey);
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE is_active = 1 
              {$siteSql}
            ORDER BY order_index ASC, name ASC
        ");
        $this->bindSiteScope($siteKey);
        return $this->db->fetchAll();
    }

    public function getCategoryWithStats(int $id, ?string $siteKey = null): object|bool
    {
        $siteSql = $this->publicVisibilitySql('forum_category', $siteKey, 'c');
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
              {$siteSql}
            GROUP BY c.id
        ");
        $this->db->bind(':id', $id);
        $this->bindSiteScope($siteKey);
        return $this->db->fetchOne();
    }

    public function getAllWithStats(?string $siteKey = null): array
    {
        $siteSql = $this->publicVisibilitySql('forum_category', $siteKey, 'c');
        $this->db->query("
            SELECT 
                c.*,
                COUNT(DISTINCT t.id) as topics_count,
                COUNT(DISTINCT r.id) as replies_count
            FROM {$this->table} c
            LEFT JOIN forum_topics t ON t.id_category = c.id AND t.is_approved = 1
            LEFT JOIN forum_replies r ON r.id_topic = t.id AND r.is_approved = 1
            WHERE c.is_active = 1
              {$siteSql}
            GROUP BY c.id
            ORDER BY c.order_index ASC, c.name ASC
        ");
        $this->bindSiteScope($siteKey);
        return $this->db->fetchAll();
    }
}





