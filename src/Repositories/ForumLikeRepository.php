<?php

namespace App\Repositories;

class ForumLikeRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "forum_likes";
        $this->db = new Connection();
    }

    public function hasUserLikedTopic(int $userId, int $topicId): bool
    {
        $this->db->query("
            SELECT COUNT(*) as count 
            FROM {$this->table} 
            WHERE id_user = :user_id AND id_topic = :topic_id
        ");
        $this->db->bind(':user_id', $userId);
        $this->db->bind(':topic_id', $topicId);
        $result = $this->db->fetchOne();
        return $result && $result->count > 0;
    }

    public function hasUserLikedReply(int $userId, int $replyId): bool
    {
        $this->db->query("
            SELECT COUNT(*) as count 
            FROM {$this->table} 
            WHERE id_user = :user_id AND id_reply = :reply_id
        ");
        $this->db->bind(':user_id', $userId);
        $this->db->bind(':reply_id', $replyId);
        $result = $this->db->fetchOne();
        return $result && $result->count > 0;
    }

    public function toggleTopicLike(int $userId, int $topicId): bool
    {
        if ($this->hasUserLikedTopic($userId, $topicId)) {
            $this->db->query("
                DELETE FROM {$this->table} 
                WHERE id_user = :user_id AND id_topic = :topic_id
            ");
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':topic_id', $topicId);
            $this->db->execute();
            return false;
        } else {
            $this->add([
                'id_user' => $userId,
                'id_topic' => $topicId,
                'id_reply' => null
            ]);
            return true;
        }
    }

    public function toggleReplyLike(int $userId, int $replyId): bool
    {
        if ($this->hasUserLikedReply($userId, $replyId)) {
            $this->db->query("
                DELETE FROM {$this->table} 
                WHERE id_user = :user_id AND id_reply = :reply_id
            ");
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':reply_id', $replyId);
            $this->db->execute();
            return false;
        } else {
            $this->add([
                'id_user' => $userId,
                'id_topic' => null,
                'id_reply' => $replyId
            ]);
            return true;
        }
    }

    public function getUserLikesForTopics(int $userId, array $topicIds): array
    {
        if (empty($topicIds)) return [];
        
        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $this->db->query("
            SELECT id_topic 
            FROM {$this->table} 
            WHERE id_user = ? AND id_topic IN ($placeholders)
        ");
        
        $this->db->bind(1, $userId);
        foreach ($topicIds as $index => $topicId) {
            $this->db->bind($index + 2, $topicId);
        }
        
        $results = $this->db->fetchAll();
        return array_column($results, 'id_topic');
    }
}





