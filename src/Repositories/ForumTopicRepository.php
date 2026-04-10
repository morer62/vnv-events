<?php

namespace App\Repositories;

class ForumTopicRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "forum_topics";
        $this->db = new Connection();
    }

    public function getTopicsByCategory(int $categoryId, int $limit = 20, int $offset = 0, string $filter = 'recent'): array
    {
        switch ($filter) {
            case 'comments':
                $orderBy = 'ORDER BY t.is_pinned DESC, t.last_reply_at DESC, t.created_at DESC';
                $whereClause = 'WHERE t.id_category = :category_id AND t.is_approved = 1 AND t.last_reply_at IS NOT NULL';
                break;
            case 'popular':
                $orderBy = 'ORDER BY t.is_pinned DESC, t.likes_count DESC, t.replies_count DESC, t.views_count DESC';
                $whereClause = 'WHERE t.id_category = :category_id AND t.is_approved = 1';
                break;
            case 'views':
                $orderBy = 'ORDER BY t.is_pinned DESC, t.views_count DESC, t.created_at DESC';
                $whereClause = 'WHERE t.id_category = :category_id AND t.is_approved = 1';
                break;
            default:
                $orderBy = 'ORDER BY t.is_pinned DESC, t.created_at DESC';
                $whereClause = 'WHERE t.id_category = :category_id AND t.is_approved = 1';
                break;
        }

        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                u.email as user_email,
                c.name as category_name,
                c.color as category_color,
                COALESCE(lr_user.name, '') as last_reply_user_name,
                COALESCE(lr_user.lastname, '') as last_reply_user_lastname
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            LEFT JOIN users lr_user ON lr_user.id = t.last_reply_user_id
            {$whereClause}
            {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        $this->db->bind(':category_id', $categoryId);
        $this->db->bind(':limit', $limit);
        $this->db->bind(':offset', $offset);
        return $this->db->fetchAll();
    }

    public function getTopicWithAuthor(int $id): object|bool
    {
        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                u.email as user_email,
                u.level as user_level,
                c.name as category_name,
                c.color as category_color
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            WHERE t.id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->fetchOne();
    }

    public function getRecentTopics(int $limit = 10, int $offset = 0): array
    {
        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                c.name as category_name,
                c.color as category_color
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            WHERE t.is_approved = 1
            ORDER BY t.is_pinned DESC, t.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $this->db->bind(':limit', $limit);
        $this->db->bind(':offset', $offset);
        return $this->db->fetchAll();
    }

    public function countAllTopics(): int
    {
        $this->db->query("
            SELECT COUNT(*) as total 
            FROM {$this->table} 
            WHERE is_approved = 1
        ");
        $result = $this->db->fetchOne();
        return $result ? (int)$result->total : 0;
    }

    public function getPopularTopics(int $limit = 10, int $offset = 0): array
    {
        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                c.name as category_name,
                c.color as category_color
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            WHERE t.is_approved = 1
            ORDER BY t.is_pinned DESC, t.likes_count DESC, t.replies_count DESC, t.views_count DESC
            LIMIT :limit OFFSET :offset
        ");
        $this->db->bind(':limit', $limit);
        $this->db->bind(':offset', $offset);
        return $this->db->fetchAll();
    }

    public function getRecentCommentsTopics(int $limit = 10, int $offset = 0): array
    {
        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                c.name as category_name,
                c.color as category_color
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            WHERE t.is_approved = 1 AND t.last_reply_at IS NOT NULL
            ORDER BY t.is_pinned DESC, t.last_reply_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $this->db->bind(':limit', $limit);
        $this->db->bind(':offset', $offset);
        return $this->db->fetchAll();
    }

    public function countRecentCommentsTopics(): int
    {
        $this->db->query("
            SELECT COUNT(*) as total 
            FROM {$this->table} 
            WHERE is_approved = 1 AND last_reply_at IS NOT NULL
        ");
        $result = $this->db->fetchOne();
        return $result ? (int)$result->total : 0;
    }

    public function getMostViewedTopics(int $limit = 10, int $offset = 0): array
    {
        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                c.name as category_name,
                c.color as category_color
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            WHERE t.is_approved = 1
            ORDER BY t.is_pinned DESC, t.views_count DESC, t.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $this->db->bind(':limit', $limit);
        $this->db->bind(':offset', $offset);
        return $this->db->fetchAll();
    }

    public function incrementViewCount(int $id): void
    {
        $this->db->query("UPDATE {$this->table} SET views_count = views_count + 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        $this->db->execute();
    }

    public function searchTopics(string $query, int $limit = 20): array
    {
        $this->db->query("
            SELECT 
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                c.name as category_name,
                c.color as category_color
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            WHERE t.is_approved = 1 
            AND (t.title LIKE :query OR t.content LIKE :query)
            ORDER BY t.created_at DESC
            LIMIT :limit
        ");
        $searchQuery = '%' . $query . '%';
        $this->db->bind(':query', $searchQuery);
        $this->db->bind(':limit', $limit);
        return $this->db->fetchAll();
    }

    public function countByCategory(int $categoryId): int
    {
        $this->db->query("
            SELECT COUNT(*) as total 
            FROM {$this->table} 
            WHERE id_category = :category_id AND is_approved = 1
        ");
        $this->db->bind(':category_id', $categoryId);
        $result = $this->db->fetchOne();
        return $result ? (int)$result->total : 0;
    }
}



