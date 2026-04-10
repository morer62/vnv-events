<?php

namespace App\Repositories;

class EventsRepository extends BaseRepository
{
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_CLOSED = 'closed';
    const STATUS_CANCELLED = 'cancelled';

    const EVENT_TYPES = [
        'wedding' => 'Wedding',
        'birthday' => 'Birthday Party',
        'sweet_16' => 'Sweet 16',
        'graduation' => 'Graduation',
        'corporate' => 'Corporate Event',
        'anniversary' => 'Anniversary',
        'baby_shower' => 'Baby Shower',
        'bridal_shower' => 'Bridal Shower',
        'retirement' => 'Retirement Party',
        'other' => 'Other'
    ];

    public function __construct()
    {
        $this->table = "events";
        $this->db = new Connection();
    }

    public function generateUniqueSlug(string $eventName): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $eventName)));
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $this->db->query("SELECT id FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $this->db->bind(':slug', $slug);
        return $this->db->fetchOne() !== false;
    }

    public function getBySlug(string $slug): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE slug = :slug LIMIT 1");
        $this->db->bind(':slug', $slug);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getAllByUser(int $userId): array
    {
        $this->db->query("
            SELECT e.*, 
                   (SELECT COUNT(*) FROM event_guests WHERE id_event = e.id) as total_guests,
                   (SELECT COUNT(*) FROM event_guests WHERE id_event = e.id AND rsvp_status = 'confirmed') as confirmed_guests
            FROM {$this->table} e
            WHERE e.id_user = :user_id
            ORDER BY e.event_date DESC, e.created_at DESC
        ");
        $this->db->bind(':user_id', $userId);
        return $this->db->fetchAll();
    }

    public function getEventStats(int $eventId): array
    {
        $this->db->query("
            SELECT 
                COUNT(*) as total_invited,
                SUM(CASE WHEN rsvp_status = 'confirmed' THEN 1 ELSE 0 END) as total_confirmed,
                SUM(CASE WHEN rsvp_status = 'declined' THEN 1 ELSE 0 END) as total_declined,
                SUM(CASE WHEN rsvp_status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN rsvp_status = 'confirmed' THEN plus_ones ELSE 0 END) as total_plus_ones,
                SUM(CASE WHEN invitation_sent = 1 THEN 1 ELSE 0 END) as invitations_sent,
                SUM(CASE WHEN invitation_opened = 1 THEN 1 ELSE 0 END) as invitations_opened
            FROM event_guests
            WHERE id_event = :event_id
        ");
        $this->db->bind(':event_id', $eventId);
        $result = $this->db->fetchOne();
        
        if (!$result) {
            return [
                'total_invited' => 0,
                'total_confirmed' => 0,
                'total_declined' => 0,
                'total_pending' => 0,
                'total_plus_ones' => 0,
                'total_attending' => 0,
                'invitations_sent' => 0,
                'invitations_opened' => 0,
                'response_rate' => 0
            ];
        }

        $totalAttending = $result->total_confirmed + $result->total_plus_ones;
        $responseRate = $result->total_invited > 0 
            ? round((($result->total_confirmed + $result->total_declined) / $result->total_invited) * 100, 2) 
            : 0;

        return [
            'total_invited' => (int)$result->total_invited,
            'total_confirmed' => (int)$result->total_confirmed,
            'total_declined' => (int)$result->total_declined,
            'total_pending' => (int)$result->total_pending,
            'total_plus_ones' => (int)$result->total_plus_ones,
            'total_attending' => $totalAttending,
            'invitations_sent' => (int)$result->invitations_sent,
            'invitations_opened' => (int)$result->invitations_opened,
            'response_rate' => $responseRate
        ];
    }

    public function markAsPaid(int $eventId, float $amount, string $paymentId = null): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET is_paid = 1, 
                payment_date = NOW(), 
                payment_amount = :amount,
                status = 'active'
            WHERE id = :event_id
        ");
        $this->db->bind(':event_id', $eventId);
        $this->db->bind(':amount', $amount);
        return (bool)$this->db->execute();
    }

    public function registerEventPaymentToAll(object $event, float $amount): void
    {
        $paymentDate = date("Y-m-d");
        $renewalDate = date("Y-m-d", strtotime("+30 days"));

        $db = new \App\Repositories\Connection();
        $db->query("INSERT INTO payments_all 
        (user_id, concept, concept_id, payment_date, renewal, total, status, reference)
        VALUES (:user_id, 'Event', :concept_id, :payment_date, :renewal, :total, 'ACTIVE', :ref)");

        $db->bind(":user_id", $event->id_user);
        $db->bind(":concept_id", $event->id);
        $db->bind(":payment_date", $paymentDate);
        $db->bind(":renewal", $renewalDate);
        $db->bind(":total", $amount);
        $db->bind(":ref", "Event invitation system - " . $event->event_name);

        $db->execute();

        try {
            $affiliateService = new \App\Services\AffiliateService();
            $affiliateService->createCommission($event->id_user, 'event_creation', $amount, "event_{$event->id}", null, null);
        } catch (\Exception $e) {
            error_log("Error creating affiliate commission for event payment: " . $e->getMessage());
        }
    }

    public function canChangeDateAgain(int $eventId): bool
    {
        $event = $this->getOne(['id' => $eventId]);
        if (!$event) {
            return false;
        }
        
        $maxChanges = intval($_ENV['MAX_EVENT_DATE_CHANGES'] ?? 2);
        return ($event->date_changes_count ?? 0) < $maxChanges;
    }

    public function incrementDateChangeCount(int $eventId): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET date_changes_count = date_changes_count + 1 
            WHERE id = :event_id
        ");
        $this->db->bind(':event_id', $eventId);
        return (bool)$this->db->execute();
    }

    public function updateWithDateCheck(int $eventId, array $data): array
    {
        $event = $this->getOne(['id' => $eventId]);
        
        if (!$event) {
            return ['success' => false, 'message' => 'Event not found'];
        }

        $isDateChanged = isset($data['event_date']) && $data['event_date'] !== $event->event_date;
        
        $maxChanges = intval($_ENV['MAX_EVENT_DATE_CHANGES'] ?? 2);
        
        if ($isDateChanged) {
            if (!$this->canChangeDateAgain($eventId)) {
                return [
                    'success' => false, 
                    'message' => 'You have reached the maximum number of date changes (' . $maxChanges . ') for this event. Please create a new event for a different date.'
                ];
            }
            
            $this->incrementDateChangeCount($eventId);
        }

        $this->update($data, ['id' => $eventId]);
        
        if ($isDateChanged) {
            $newCount = ($event->date_changes_count ?? 0) + 1;
            return [
                'success' => true, 
                'message' => 'Event updated successfully (Date change ' . $newCount . '/' . $maxChanges . ' used)'
            ];
        }
        
        return [
            'success' => true, 
            'message' => 'Event updated successfully'
        ];
    }
}


