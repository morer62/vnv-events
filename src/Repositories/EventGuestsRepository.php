<?php

namespace App\Repositories;

class EventGuestsRepository extends BaseRepository
{
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_DECLINED = 'declined';
    const STATUS_TENTATIVE = 'tentative';

    public function __construct()
    {
        $this->table = "event_guests";
        $this->db = new Connection();
    }

    public function generateAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function getByToken(string $token): ?object
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE access_token = :token LIMIT 1");
        $this->db->bind(':token', $token);
        $result = $this->db->fetchOne();
        return $result ?: null;
    }

    public function getAllByEvent(int $eventId, array $filters = []): array
    {
        $conditions = ["id_event = :event_id"];
        $params = [':event_id' => $eventId];

        if (!empty($filters['rsvp_status'])) {
            $conditions[] = "rsvp_status = :rsvp_status";
            $params[':rsvp_status'] = $filters['rsvp_status'];
        }

        if (!empty($filters['guest_group'])) {
            $conditions[] = "guest_group = :guest_group";
            $params[':guest_group'] = $filters['guest_group'];
        }

        if (!empty($filters['table_number'])) {
            $conditions[] = "table_number = :table_number";
            $params[':table_number'] = $filters['table_number'];
        }

        $whereClause = implode(' AND ', $conditions);

        $this->db->query("
            SELECT * FROM {$this->table}
            WHERE {$whereClause}
            ORDER BY last_name ASC, first_name ASC
        ");

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return $this->db->fetchAll();
    }

    public function updateRSVP(int $guestId, array $data): bool
    {
        $updates = [];
        $params = [':id' => $guestId];

        if (isset($data['rsvp_status'])) {
            $updates[] = "rsvp_status = :rsvp_status";
            $params[':rsvp_status'] = $data['rsvp_status'];
        }

        if (isset($data['plus_ones'])) {
            $updates[] = "plus_ones = :plus_ones";
            $params[':plus_ones'] = $data['plus_ones'];
        }

        if (isset($data['plus_ones_names'])) {
            $updates[] = "plus_ones_names = :plus_ones_names";
            $params[':plus_ones_names'] = is_array($data['plus_ones_names']) 
                ? json_encode($data['plus_ones_names']) 
                : $data['plus_ones_names'];
        }

        if (isset($data['decline_reason'])) {
            $updates[] = "decline_reason = :decline_reason";
            $params[':decline_reason'] = $data['decline_reason'];
        }

        if (isset($data['meal_preference'])) {
            $updates[] = "meal_preference = :meal_preference";
            $params[':meal_preference'] = $data['meal_preference'];
        }

        if (isset($data['dietary_restrictions'])) {
            $updates[] = "dietary_restrictions = :dietary_restrictions";
            $params[':dietary_restrictions'] = $data['dietary_restrictions'];
        }

        if (isset($data['special_notes'])) {
            $updates[] = "special_notes = :special_notes";
            $params[':special_notes'] = $data['special_notes'];
        }

        $updates[] = "rsvp_date = NOW()";

        $updateClause = implode(', ', $updates);

        $this->db->query("UPDATE {$this->table} SET {$updateClause} WHERE id = :id");
        
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return (bool)$this->db->execute();
    }

    public function markInvitationSent(int $guestId): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET invitation_sent = 1, invitation_sent_at = NOW() 
            WHERE id = :id
        ");
        $this->db->bind(':id', $guestId);
        return (bool)$this->db->execute();
    }

    public function markInvitationOpened(int $guestId): bool
    {
        $this->db->query("
            UPDATE {$this->table} 
            SET invitation_opened = 1, invitation_opened_at = NOW() 
            WHERE id = :id AND invitation_opened = 0
        ");
        $this->db->bind(':id', $guestId);
        return (bool)$this->db->execute();
    }

    public function importFromCSV(int $eventId, array $csvData): array
    {
        $imported = 0;
        $errors = [];

        foreach ($csvData as $index => $row) {
            try {
                if (empty($row['email'])) {
                    $errors[] = "Row " . ($index + 1) . ": Email is required";
                    continue;
                }

                $accessToken = $this->generateAccessToken();

                $this->add([
                    'id_event' => $eventId,
                    'first_name' => $row['first_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'email' => $row['email'],
                    'phone' => $row['phone'] ?? null,
                    'guest_group' => $row['guest_group'] ?? null,
                    'access_token' => $accessToken
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors
        ];
    }
}


