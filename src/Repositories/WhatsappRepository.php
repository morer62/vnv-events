<?php

namespace App\Repositories;

class WhatsappRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "whatsapp_messages";
        $this->db = new Connection();
    }

   

    public function archiveClient(int $id): void
    {
        $this->db->query("UPDATE whatsapp_clients SET is_deleted = 1 WHERE id = :id");
        $this->db->bind(":id", $id);
        $this->db->execute();
    }

    public function unarchiveClient(int $id): void
    {
        $this->db->query("UPDATE whatsapp_clients SET is_deleted = 0 WHERE id = :id");
        $this->db->bind(":id", $id);
        $this->db->execute();
    }


  

    public function findClientByPhone(string $phone): ?object
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return null;

        $this->db->query("
            SELECT c.*
            FROM whatsapp_clients c
            INNER JOIN whatsapp_clients_accounts ca ON ca.id_client = c.id
            WHERE c.phone = :phone AND ca.id_whatsapp_account = :account
        ");
        $this->db->bind(":phone", $phone);
        $this->db->bind(":account", $active->id);

        return $this->db->fetchOne() ?: null;
    }

   public function storeMessageWithChannel(int $client_id, string $from, string $to, string $message, string $direction, string $channel): int
    {
        // Buscar cuenta por número receptor
        $this->db->query("SELECT id FROM whatsapp_account WHERE phone = :phone");
        $this->db->bind(":phone", $to);
        $account = $this->db->fetchOne();
        if (!$account) return 0;

        $this->db->query("
            INSERT INTO whatsapp_messages 
            (client_id, phone_from, phone_to, message, direction, channel, id_whatsapp_account)
            VALUES (:client_id, :from, :to, :message, :direction, :channel, :account)
        ");
        $this->db->bind(":client_id", $client_id);
        $this->db->bind(":from", $from);
        $this->db->bind(":to", $to);
        $this->db->bind(":message", $message);
        $this->db->bind(":direction", $direction);
        $this->db->bind(":channel", "sms");
        $this->db->bind(":account", $account->id);
        $this->db->execute();

        return $this->getLastId();
}



    public function findClientById(int $id): ?object
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return null;

        $this->db->query("
            SELECT c.*
            FROM whatsapp_clients c
            INNER JOIN whatsapp_clients_accounts ca ON ca.id_client = c.id
            WHERE c.id = :id AND ca.id_whatsapp_account = :account AND c.is_deleted = 0
        ");
        $this->db->bind(":id", $id);
        $this->db->bind(":account", $active->id);

        return $this->db->fetchOne() ?: null;
    }

    public function updateClientName(int $id, string $name): void
    {
        $this->db->query("UPDATE whatsapp_clients SET name = :name WHERE id = :id");
        $this->db->bind("name", $name);
        $this->db->bind("id", $id);
        $this->db->execute();
    }

    public function getAllClients(): array
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return [];

        $this->db->query("
            SELECT 
                c.*,
                (
                    SELECT COUNT(*) 
                    FROM whatsapp_messages m 
                    WHERE m.client_id = c.id 
                    AND m.direction = 'inbound' 
                    AND m.is_read = 0 
                    AND m.id_whatsapp_account = :id
                ) AS unread_count
            FROM whatsapp_clients c
            INNER JOIN whatsapp_clients_accounts ca ON ca.id_client = c.id
            WHERE ca.id_whatsapp_account = :id AND c.is_deleted = 0
            ORDER BY c.name ASC
        ");
        $this->db->bind(":id", $active->id);
        return $this->db->fetchAll();
    }


    public function getAllArchivedClients(): array
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return [];

        $this->db->query("
            SELECT 
                c.*,
                (
                    SELECT COUNT(*) 
                    FROM whatsapp_messages m 
                    WHERE m.client_id = c.id 
                    AND m.direction = 'inbound' 
                    AND m.is_read = 0 
                    AND m.id_whatsapp_account = :id
                ) AS unread_count
            FROM whatsapp_clients c
            INNER JOIN whatsapp_clients_accounts ca ON ca.id_client = c.id
            WHERE ca.id_whatsapp_account = :id AND c.is_deleted = 1
            ORDER BY c.name ASC
        ");
        $this->db->bind(":id", $active->id);
        return $this->db->fetchAll();
    }


    public function storeMedia(int $messageId, string $url, string $type): void
    {
        $this->db->query("
            INSERT INTO whatsapp_media (message_id, url, type)
            VALUES (:message_id, :url, :type)
        ");
        $this->db->bind(":message_id", $messageId);
        $this->db->bind(":url", $url);
        $this->db->bind(":type", $type);
        $this->db->execute();
    }

    public function getMediaByMessageId(int $messageId): array
    {
        $this->db->query("
            SELECT url, type
            FROM whatsapp_media
            WHERE message_id = :message_id
        ");
        $this->db->bind(":message_id", $messageId);
        return $this->db->fetchAll();
    }

    public function markMessagesAsRead(int $client_id): void
    {
        $this->db->query("
            UPDATE whatsapp_messages 
            SET is_read = 1 
            WHERE client_id = :client_id AND direction = 'inbound' AND is_read = 0
        ");
        $this->db->bind(":client_id", $client_id);
        $this->db->execute();
    }

    public function storeOutboundMessage(int $client_id, string $from, string $to, string $body): void
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return;

        $this->db->query("
            INSERT INTO whatsapp_messages (client_id, phone_from, phone_to, message, direction, id_whatsapp_account)
            VALUES (:client_id, :from, :to, :message, 'outbound', :account)
        ");
        $this->db->bind(":client_id", $client_id);
        $this->db->bind(":from", $from);
        $this->db->bind(":to", $to);
        $this->db->bind(":message", $body);
        $this->db->bind(":account", $active->id);
        $this->db->execute();
    }

    public function sendMessage(int $client_id, string $from, string $to, string $message): void
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return;

        $this->db->query("
            INSERT INTO whatsapp_messages (client_id, phone_from, phone_to, message, direction, id_whatsapp_account)
            VALUES (:client_id, :from, :to, :message, 'outbound', :account)
        ");
        $this->db->bind(":client_id", $client_id);
        $this->db->bind(":from", $from);
        $this->db->bind(":to", $to);
        $this->db->bind(":message", $message);
        $this->db->bind(":account", $active->id);
        $this->db->execute();
    }

    public function getMessagesByClient(int $client_id, string $channel = 'whatsapp'): array
    {
        $client = $this->findClientById($client_id);
        if (!$client || (int)$client->is_deleted === 1) {
            return []; // no mostrar nada si fue eliminado
        }

        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return [];

        $this->db->query("
            SELECT 
                m.id, m.phone_from, m.phone_to, m.message, m.direction, m.created_at, c.name 
            FROM whatsapp_messages m
            LEFT JOIN whatsapp_clients c ON m.client_id = c.id
            WHERE m.client_id = :client_id 
            AND m.id_whatsapp_account = :account
            AND m.channel = :channel
            ORDER BY m.created_at ASC
        ");
        $this->db->bind(":client_id", $client_id);
        $this->db->bind(":account", $active->id);
        $this->db->bind(":channel", $channel);
        $messages = $this->db->fetchAll();

        foreach ($messages as &$msg) {
            $this->db->query("
                SELECT url, type 
                FROM whatsapp_media 
                WHERE message_id = :message_id
            ");
            $this->db->bind(":message_id", $msg->id);
            $msg->media = $this->db->fetchAll();
        }

        return $messages;
    }


    public function createClient(string $phone, int $accountId): int
{
    // 1. Buscar si ya existe el cliente globalmente
    $this->db->query("SELECT * FROM whatsapp_clients WHERE phone = :phone");
    $this->db->bind(":phone", $phone);
    $existing = $this->db->fetchOne();

    $clientId = $existing ? $existing->id : null;

    // 2. Si no existe, lo creamos
    if (!$clientId) {
        $this->db->query("INSERT INTO whatsapp_clients (phone, created_at) VALUES (:phone, NOW())");
        $this->db->bind(":phone", $phone);
        $this->db->execute();
        $clientId = $this->getLastId();
    }

    // 3. Verificamos si ya está vinculado a esa cuenta
    $this->db->query("SELECT COUNT(*) as total FROM whatsapp_clients_accounts WHERE id_client = :client AND id_whatsapp_account = :account");
    $this->db->bind(":client", $clientId);
    $this->db->bind(":account", $accountId);
    $exists = $this->db->fetchOne();

    if (!$exists || $exists->total == 0) {
        // 4. Si no está vinculado, lo vinculamos ahora
        $this->db->query("INSERT INTO whatsapp_clients_accounts (id_client, id_whatsapp_account) VALUES (:client, :account)");
        $this->db->bind(":client", $clientId);
        $this->db->bind(":account", $accountId);
        $this->db->execute();
    }

    return $clientId;
}




    public function storeAnonymousOutboundMessage(string $toPhone, string $fromPhone, string $message): void
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return;

        $this->db->query("
            INSERT INTO whatsapp_messages (client_id, phone_from, phone_to, message, direction, created_at, id_whatsapp_account)
            VALUES (NULL, :from, :to, :message, 'outbound', NOW(), :account)
        ");
        $this->db->bind(":from", $fromPhone);
        $this->db->bind(":to", $toPhone);
        $this->db->bind(":message", $message);
        $this->db->bind(":account", $active->id);
        $this->db->execute();
    }

    public function storeMessage(int $client_id, string $from, string $to, string $message, string $direction = 'inbound'): int
    {
        // Buscar cuenta según el número "to" (el número de empresa)
        $this->db->query("SELECT id FROM whatsapp_account WHERE phone = :phone");
        $this->db->bind(":phone", $to);
        $account = $this->db->fetchOne();
        if (!$account) return 0;

        $this->db->query("
            INSERT INTO whatsapp_messages (client_id, phone_from, phone_to, message, direction, id_whatsapp_account)
            VALUES (:client_id, :from, :to, :message, :direction, :account)
        ");
        $this->db->bind(":client_id", $client_id);
        $this->db->bind(":from", $from);
        $this->db->bind(":to", $to);
        $this->db->bind(":message", $message);
        $this->db->bind(":direction", $direction);
        $this->db->bind(":account", $account->id);
        $this->db->execute();

        return $this->getLastId();
    }


    public function sendSMS(string $to, string $body): bool
    {
        $sid = $_ENV['TWILIO_SID'];
        $token = $_ENV['TWILIO_AUTH_TOKEN'];
        $from = $_ENV['TWILIO_NUMBER']; // el mismo número usado

        $twilio = new \Twilio\Rest\Client($sid, $token);
        try {
            $twilio->messages->create("+{$to}", [
                'from' => "+{$from}",
                'body' => $body,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getClientsSortedByActivity(): array
    {
        $accountRepo = new WhatsappAccountRepository();
        $active = $accountRepo->getActive();
        if (!$active) return [];

        $this->db->query("
            SELECT 
                c.*,
                (
                    SELECT COUNT(*) 
                    FROM whatsapp_messages m 
                    WHERE m.client_id = c.id 
                    AND m.direction = 'inbound' 
                    AND m.is_read = 0 
                    AND m.id_whatsapp_account = :account
                ) AS unread_count,
                (
                    SELECT MAX(created_at)
                    FROM whatsapp_messages m2
                    WHERE m2.client_id = c.id
                    AND m2.id_whatsapp_account = :account
                ) AS last_message_at
            FROM whatsapp_clients c
            INNER JOIN whatsapp_clients_accounts ca ON ca.id_client = c.id
            WHERE ca.id_whatsapp_account = :account AND c.is_deleted = 0
            ORDER BY unread_count DESC, last_message_at DESC
        ");
        $this->db->bind(":account", $active->id);
        return $this->db->fetchAll();
    }




}
