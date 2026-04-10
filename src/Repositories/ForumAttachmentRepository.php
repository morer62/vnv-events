<?php

namespace App\Repositories;

class ForumAttachmentRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "forum_attachments";
        $this->db = new Connection();
    }

    public function getAttachmentsByTopic(int $topicId): array
    {
        try {
            $this->db->query("SELECT * FROM {$this->table} WHERE id_topic = :topic_id ORDER BY created_at ASC");
            $this->db->bind(':topic_id', $topicId);
            $this->db->execute();
            $results = $this->db->fetchAll();
            
            error_log("ForumAttachmentRepository - Direct query for topic_id: " . $topicId);
            error_log("ForumAttachmentRepository - Results count: " . count($results));
            if (!empty($results)) {
                error_log("ForumAttachmentRepository - First result: " . json_encode($results[0]));
            }
            
            return $results;
        } catch (\PDOException $e) {
            error_log("ForumAttachmentRepository - PDO Error: " . $e->getMessage());
            return [];
        }
    }

    public function getAttachmentsByReply(int $replyId): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_reply = :reply_id 
            ORDER BY created_at ASC
        ");
        $this->db->bind(':reply_id', $replyId);
        return $this->db->fetchAll();
    }

    public function getImagesByTopic(int $topicId): array
    {
        $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE id_topic = :topic_id AND is_image = 1
            ORDER BY created_at ASC
        ");
        $this->db->bind(':topic_id', $topicId);
        $this->db->execute();
        return $this->db->fetchAll();
    }

    public function deleteByTopic(int $topicId): bool
    {
        $this->db->query("DELETE FROM {$this->table} WHERE id_topic = :topic_id");
        $this->db->bind(':topic_id', $topicId);
        return $this->db->execute();
    }

    public function deleteByReply(int $replyId): bool
    {
        $this->db->query("DELETE FROM {$this->table} WHERE id_reply = :reply_id");
        $this->db->bind(':reply_id', $replyId);
        return $this->db->execute();
    }
}

