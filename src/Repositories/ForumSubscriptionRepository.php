<?php

namespace App\Repositories;

class ForumSubscriptionRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "forum_subscriptions";
        $this->db = new Connection();
    }

    public function isUserSubscribed(int $userId, int $topicId): bool
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

    public function toggleSubscription(int $userId, int $topicId): bool
    {
        if ($this->isUserSubscribed($userId, $topicId)) {
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
                'notify_email' => 1
            ]);
            return true;
        }
    }

    public function getSubscribersByTopic(int $topicId): array
    {
        $this->db->query("
            SELECT 
                s.*,
                u.name as user_name,
                u.email as user_email
            FROM {$this->table} s
            INNER JOIN users u ON u.id = s.id_user
            WHERE s.id_topic = :topic_id AND s.notify_email = 1
        ");
        $this->db->bind(':topic_id', $topicId);
        return $this->db->fetchAll();
    }
}





