<?php

namespace App\Repositories;

final class InvoicesRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = 'invoices';
        $this->db = new Connection();
    }

    public function findByOrder(int $ownerId, int $orderId): ?object
    {
        $this->db->query('SELECT * FROM invoices WHERE id_owner = :owner AND id_order = :order_id LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':order_id', $orderId);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    public function findOwned(int $ownerId, int $invoiceId): ?object
    {
        $this->db->query('SELECT * FROM invoices WHERE id_owner = :owner AND id = :id LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':id', $invoiceId);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    public function search(int $ownerId, array $filters): array
    {
        $where = ['i.id_owner = :owner'];
        $params = ['owner' => $ownerId];
        if (($filters['client'] ?? '') !== '') {
            $where[] = "(i.billing_name LIKE :client OR i.billing_email LIKE :client)";
            $params['client'] = '%'.trim((string)$filters['client']).'%';
        }
        if (($filters['number'] ?? '') !== '') {
            $where[] = 'i.invoice_number LIKE :number';
            $params['number'] = '%'.trim((string)$filters['number']).'%';
        }
        if (($filters['order'] ?? '') !== '') {
            $where[] = 'i.id_order = :order_id';
            $params['order_id'] = (int)$filters['order'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'i.status = :status';
            $params['status'] = strtoupper((string)$filters['status']);
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'DATE(i.created_at) >= :date_from';
            $params['date_from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'DATE(i.created_at) <= :date_to';
            $params['date_to'] = $filters['to'];
        }
        $sql = 'SELECT i.*, o.event_date, o.address FROM invoices i JOIN orders o ON o.id=i.id_order WHERE '.implode(' AND ', $where).' ORDER BY i.created_at DESC, i.id DESC LIMIT 500';
        $this->db->query($sql);
        foreach ($params as $key => $value) $this->db->bind(':'.$key, $value);
        return $this->db->fetchAll();
    }

    public function audit(int $invoiceId, ?int $actorId, string $action, array $details = []): void
    {
        $this->db->query('INSERT INTO invoice_audit_logs (invoice_id,actor_id,action,details_json) VALUES (:invoice,:actor,:action,:details)');
        $this->db->bind(':invoice', $invoiceId);
        $this->db->bind(':actor', $actorId);
        $this->db->bind(':action', $action);
        $this->db->bind(':details', $details ? json_encode($details, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null);
        $this->db->execute();
    }
}
