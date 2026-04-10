<?php

namespace App\Repositories;

class ForumReplyRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "forum_replies";
        $this->db = new Connection();
    }

    public function getRepliesByTopic(int $topicId): array
    {
        $this->db->query("
            SELECT 
                r.*,
                u.name as user_name,
                u.lastname as user_lastname,
                u.email as user_email,
                u.level as user_level
            FROM {$this->table} r
            INNER JOIN users u ON u.id = r.id_user
            WHERE r.id_topic = :topic_id AND r.is_approved = 1
            ORDER BY r.is_best_answer DESC, r.created_at ASC
        ");
        $this->db->bind(':topic_id', $topicId);
        return $this->db->fetchAll();
    }

    public function getRepliesWithNested(int $topicId): array
    {
        $this->db->query("
            SELECT 
                r.*,
                u.name as user_name,
                u.lastname as user_lastname,
                u.email as user_email,
                u.level as user_level,
                parent.id as parent_id,
                parent_user.name as parent_user_name,
                parent_user.lastname as parent_user_lastname
            FROM {$this->table} r
            INNER JOIN users u ON u.id = r.id_user
            LEFT JOIN {$this->table} parent ON parent.id = r.id_parent_reply
            LEFT JOIN users parent_user ON parent_user.id = parent.id_user
            WHERE r.id_topic = :topic_id AND r.is_approved = 1
            ORDER BY 
                COALESCE(r.id_parent_reply, r.id) ASC,
                r.id_parent_reply IS NULL DESC,
                r.created_at ASC
        ");
        $this->db->bind(':topic_id', $topicId);
        return $this->db->fetchAll();
    }

    public function markAsBestAnswer(int $replyId): void
    {
        $reply = $this->getOne(['id' => $replyId]);
        if (!$reply) return;

        $this->db->query("UPDATE {$this->table} SET is_best_answer = 0 WHERE id_topic = :topic_id");
        $this->db->bind(':topic_id', $reply->id_topic);
        $this->db->execute();

        $this->db->query("UPDATE {$this->table} SET is_best_answer = 1 WHERE id = :id");
        $this->db->bind(':id', $replyId);
        $this->db->execute();
    }

    public function countByTopic(int $topicId): int
    {
        $this->db->query("
            SELECT COUNT(*) as total 
            FROM {$this->table} 
            WHERE id_topic = :topic_id AND is_approved = 1
        ");
        $this->db->bind(':topic_id', $topicId);
        $result = $this->db->fetchOne();
        return $result ? (int)$result->total : 0;
    }
}





