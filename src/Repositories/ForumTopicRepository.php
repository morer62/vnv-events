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
                $whereClause = "WHERE t.id_category = :category_id AND t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED' AND t.last_reply_at IS NOT NULL";
                break;
            case 'popular':
                $orderBy = 'ORDER BY t.is_pinned DESC, t.likes_count DESC, t.replies_count DESC, t.views_count DESC';
                $whereClause = "WHERE t.id_category = :category_id AND t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'";
                break;
            case 'views':
                $orderBy = 'ORDER BY t.is_pinned DESC, t.views_count DESC, t.created_at DESC';
                $whereClause = "WHERE t.id_category = :category_id AND t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'";
                break;
            default:
                $orderBy = 'ORDER BY t.is_pinned DESC, t.created_at DESC';
                $whereClause = "WHERE t.id_category = :category_id AND t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'";
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

    public function getPublishedBySlug(string $slug): object|bool
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
            WHERE t.slug = :slug
              AND t.is_approved = 1
              AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'
            LIMIT 1
        ");
        $this->db->bind(':slug', trim(strtolower($slug)));
        return $this->db->fetchOne();
    }

    public function getTopicByIdOrSlug(string|int $value): object|bool
    {
        if (is_numeric($value)) {
            return $this->getTopicWithAuthor((int)$value);
        }

        return $this->getPublishedBySlug((string)$value);
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
            WHERE t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'
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
            WHERE is_approved = 1 AND COALESCE(status, 'PUBLISHED') = 'PUBLISHED'
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
            WHERE t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'
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
            WHERE t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED' AND t.last_reply_at IS NOT NULL
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
            WHERE is_approved = 1 AND COALESCE(status, 'PUBLISHED') = 'PUBLISHED' AND last_reply_at IS NOT NULL
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
            WHERE t.is_approved = 1 AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'
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
            AND COALESCE(t.status, 'PUBLISHED') = 'PUBLISHED'
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
            WHERE id_category = :category_id AND is_approved = 1 AND COALESCE(status, 'PUBLISHED') = 'PUBLISHED'
        ");
        $this->db->bind(':category_id', $categoryId);
        $result = $this->db->fetchOne();
        return $result ? (int)$result->total : 0;
    }

    public function getAdminTopics(?int $categoryId = null, string $search = '', int $limit = 100): array
    {
        $where = "WHERE COALESCE(t.status, 'PUBLISHED') != 'DELETED'";
        $params = [];

        if ($categoryId) {
            $where .= " AND t.id_category = :category_id";
            $params[':category_id'] = $categoryId;
        }

        if ($search !== '') {
            $where .= " AND (t.title LIKE :search OR t.content LIKE :search OR t.slug LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $this->db->query("
            SELECT
                t.*,
                u.name as user_name,
                u.lastname as user_lastname,
                c.name as category_name,
                c.color as category_color,
                COUNT(r.id) as total_replies,
                SUM(CASE WHEN COALESCE(r.status, IF(r.is_approved = 1, 'APPROVED', 'PENDING')) = 'PENDING' THEN 1 ELSE 0 END) as pending_replies
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.id_user
            INNER JOIN forum_categories c ON c.id = t.id_category
            LEFT JOIN forum_replies r ON r.id_topic = t.id AND COALESCE(r.status, 'APPROVED') != 'DELETED'
            {$where}
            GROUP BY t.id
            ORDER BY t.is_pinned DESC, t.updated_at DESC, t.created_at DESC
            LIMIT :limit
        ");

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        $this->db->bind(':limit', $limit);

        return $this->db->fetchAll() ?: [];
    }

    public function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), '-');
        $base = $base !== '' ? substr($base, 0, 200) : 'forum-topic';
        $slug = $base;
        $counter = 2;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = substr($base, 0, 190) . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM {$this->table} WHERE slug = :slug";
        if ($excludeId) {
            $sql .= " AND id != :id";
        }
        $sql .= " LIMIT 1";

        $this->db->query($sql);
        $this->db->bind(':slug', $slug);
        if ($excludeId) {
            $this->db->bind(':id', $excludeId);
        }

        return (bool)$this->db->fetchOne();
    }

    public function publicUrl(object $topic): string
    {
        $slug = $topic->slug ?? ('topic-' . $topic->id);
        return '/forums/' . trim((string)$slug, '/') . '/';
    }

    public function refreshReplyStats(int $topicId): void
    {
        $this->db->query("
            UPDATE {$this->table} t
            SET replies_count = (
                    SELECT COUNT(*)
                    FROM forum_replies r
                    WHERE r.id_topic = :topic_id
                      AND r.is_approved = 1
                      AND COALESCE(r.status, 'APPROVED') = 'APPROVED'
                      AND COALESCE(r.is_public, 1) = 1
                ),
                last_reply_at = (
                    SELECT MAX(r.created_at)
                    FROM forum_replies r
                    WHERE r.id_topic = :topic_id
                      AND r.is_approved = 1
                      AND COALESCE(r.status, 'APPROVED') = 'APPROVED'
                      AND COALESCE(r.is_public, 1) = 1
                ),
                last_reply_user_id = (
                    SELECT r.id_user
                    FROM forum_replies r
                    WHERE r.id_topic = :topic_id
                      AND r.is_approved = 1
                      AND COALESCE(r.status, 'APPROVED') = 'APPROVED'
                      AND COALESCE(r.is_public, 1) = 1
                    ORDER BY r.created_at DESC
                    LIMIT 1
                )
            WHERE t.id = :topic_id
        ");
        $this->db->bind(':topic_id', $topicId);
        $this->db->execute();
    }

    public function getPublishedSitemapEntries(): array
    {
        $this->db->query("
            SELECT id, title, slug, updated_at, published_at, created_at
            FROM {$this->table}
            WHERE is_approved = 1
              AND COALESCE(status, 'PUBLISHED') = 'PUBLISHED'
              AND slug IS NOT NULL
              AND slug != ''
            ORDER BY updated_at DESC, published_at DESC, created_at DESC
        ");

        return $this->db->fetchAll() ?: [];
    }
}



