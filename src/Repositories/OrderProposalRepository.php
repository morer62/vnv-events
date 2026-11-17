<?php

namespace App\Repositories;

use RuntimeException;

class OrderProposalRepository
{
    private Connection $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getByOrder(int $orderId): ?object
    {
        $this->db->query(
            'SELECT p.*, r.revision_number, r.content_html, r.content_text, r.change_note,
                    r.created_at AS revision_created_at
             FROM order_proposals p
             LEFT JOIN order_proposal_revisions r ON r.id = p.current_revision_id
             WHERE p.id_order = :order_id
             LIMIT 1'
        );
        $this->db->bind(':order_id', $orderId);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    public function getOrCreate(int $orderId, int $ownerId, int $actorId): object
    {
        $existing = $this->getByOrder($orderId);
        if ($existing) {
            return $existing;
        }

        $this->db->query(
            'INSERT INTO order_proposals (id_order, id_owner, status, created_by, updated_by)
             VALUES (:order_id, :owner_id, :status, :actor_id, :actor_id)'
        );
        $this->db->bind(':order_id', $orderId);
        $this->db->bind(':owner_id', $ownerId);
        $this->db->bind(':status', 'DRAFT');
        $this->db->bind(':actor_id', $actorId);
        $this->db->execute();

        $created = $this->getByOrder($orderId);
        if (!$created) {
            throw new RuntimeException('The proposal could not be created.');
        }
        return $created;
    }

    public function createRevision(int $proposalId, string $html, string $text, ?string $changeNote, int $actorId): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT COALESCE(MAX(revision_number), 0) + 1 AS next_revision FROM order_proposal_revisions WHERE id_proposal = :id FOR UPDATE');
            $this->db->bind(':id', $proposalId);
            $next = $this->db->fetchOne();
            $revisionNumber = (int)($next->next_revision ?? 1);

            $this->db->query(
                'INSERT INTO order_proposal_revisions
                    (id_proposal, revision_number, content_html, content_text, change_note, created_by)
                 VALUES (:proposal_id, :revision_number, :content_html, :content_text, :change_note, :created_by)'
            );
            $this->db->bind(':proposal_id', $proposalId);
            $this->db->bind(':revision_number', $revisionNumber);
            $this->db->bind(':content_html', $html);
            $this->db->bind(':content_text', $text);
            $this->db->bind(':change_note', $changeNote);
            $this->db->bind(':created_by', $actorId);
            $this->db->execute();
            $revisionId = (int)$this->db->lastId();

            $this->db->query(
                'UPDATE order_proposals
                 SET current_revision_id = :revision_id, status = :status, updated_by = :actor_id
                 WHERE id = :proposal_id'
            );
            $this->db->bind(':revision_id', $revisionId);
            $this->db->bind(':status', 'DRAFT');
            $this->db->bind(':actor_id', $actorId);
            $this->db->bind(':proposal_id', $proposalId);
            $this->db->execute();
            $this->db->commit();
            return $revisionId;
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function setStatus(int $proposalId, string $status, int $actorId, ?int $approvedRevisionId = null): void
    {
        $sql = 'UPDATE order_proposals SET status = :status, updated_by = :actor_id';
        if ($approvedRevisionId !== null) {
            $sql .= ', approved_revision_id = :approved_revision_id';
        }
        $sql .= ' WHERE id = :proposal_id';
        $this->db->query($sql);
        $this->db->bind(':status', $status);
        $this->db->bind(':actor_id', $actorId);
        if ($approvedRevisionId !== null) {
            $this->db->bind(':approved_revision_id', $approvedRevisionId);
        }
        $this->db->bind(':proposal_id', $proposalId);
        $this->db->execute();
    }

    public function addApproval(array $data): int
    {
        $this->db->query(
            'INSERT INTO order_proposal_approvals
                (id_proposal, id_revision, action, actor_user_id, reason, signature_method,
                 signature_value, signed_pdf_path, content_hash, ip_address, user_agent, user_local_timestamp)
             VALUES
                (:id_proposal, :id_revision, :action, :actor_user_id, :reason, :signature_method,
                 :signature_value, :signed_pdf_path, :content_hash, :ip_address, :user_agent, :user_local_timestamp)'
        );
        foreach ([
            'id_proposal', 'id_revision', 'action', 'actor_user_id', 'reason', 'signature_method',
            'signature_value', 'signed_pdf_path', 'content_hash', 'ip_address', 'user_agent', 'user_local_timestamp',
        ] as $field) {
            $this->db->bind(':' . $field, $data[$field] ?? null);
        }
        $this->db->execute();
        return (int)$this->db->lastId();
    }

    public function addAudit(int $proposalId, ?int $revisionId, string $eventType, int $actorId, ?string $reason = null, array $metadata = []): void
    {
        $this->db->query(
            'INSERT INTO order_proposal_audit_logs
                (id_proposal, id_revision, event_type, actor_user_id, reason, metadata_json)
             VALUES (:proposal_id, :revision_id, :event_type, :actor_id, :reason, :metadata_json)'
        );
        $this->db->bind(':proposal_id', $proposalId);
        $this->db->bind(':revision_id', $revisionId);
        $this->db->bind(':event_type', $eventType);
        $this->db->bind(':actor_id', $actorId);
        $this->db->bind(':reason', $reason);
        $this->db->bind(':metadata_json', $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
        $this->db->execute();
    }

    /** @return object[] */
    public function getHistory(int $proposalId): array
    {
        $this->db->query(
            "SELECT h.*, CONCAT_WS(' ', u.name, u.lastname) AS actor_name
             FROM (
                 SELECT id_proposal, id_revision, event_type, actor_user_id, reason, metadata_json, created_at
                 FROM order_proposal_audit_logs WHERE id_proposal = :audit_id
                 UNION ALL
                 SELECT id_proposal, id_revision, CONCAT('SIGNATURE_', action), actor_user_id, reason,
                        JSON_OBJECT('signed_pdf_path', signed_pdf_path, 'content_hash', content_hash,
                                    'signature_method', signature_method), created_at
                 FROM order_proposal_approvals WHERE id_proposal = :approval_id
             ) h
             LEFT JOIN users u ON u.id = h.actor_user_id
             ORDER BY h.created_at DESC"
        );
        $this->db->bind(':audit_id', $proposalId);
        $this->db->bind(':approval_id', $proposalId);
        return $this->db->fetchAll();
    }

    public function getRevision(int $revisionId): ?object
    {
        $this->db->query('SELECT * FROM order_proposal_revisions WHERE id = :id LIMIT 1');
        $this->db->bind(':id', $revisionId);
        $row = $this->db->fetchOne();
        return $row ?: null;
    }

    /** @return array<int,object> */
    public function getSummariesByOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query(
            "SELECT p.id, p.id_order, p.status, p.current_revision_id, p.approved_revision_id,
                    (SELECT a.signed_pdf_path
                     FROM order_proposal_approvals a
                     WHERE a.id_proposal = p.id
                       AND a.action = 'APPROVED'
                       AND a.signed_pdf_path IS NOT NULL
                     ORDER BY a.id DESC
                     LIMIT 1) AS signed_pdf_path
             FROM order_proposals p
             WHERE p.id_order IN ({$placeholders})"
        );
        foreach ($ids as $index => $id) {
            $this->db->bind($index + 1, $id);
        }

        $summaries = [];
        foreach ($this->db->fetchAll() as $row) {
            $summaries[(int)$row->id_order] = $row;
        }
        return $summaries;
    }

    public static function statusLabel(?string $status, bool $hasContent = false): string
    {
        return match ((string)$status) {
            'DRAFT' => $hasContent ? 'Draft' : 'No details yet',
            'PENDING_APPROVAL' => 'Awaiting client signature',
            'APPROVED' => 'Approved and signed',
            'CHANGES_REQUESTED' => 'Changes requested',
            default => 'No details yet',
        };
    }

    /** Adds proposal status fields to order objects without N+1 queries. */
    public function attachSummaries(array $orders): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($order) => (int)($order->id ?? 0), $orders))));
        if (!$ids) {
            return $orders;
        }
        $summaries = $this->getSummariesByOrderIds($ids);
        foreach ($orders as $order) {
            $summary = $summaries[(int)($order->id ?? 0)] ?? null;
            $order->proposal_status = $summary->status ?? 'NOT_STARTED';
            $order->proposal_has_content = $summary ? (bool)$summary->current_revision_id : false;
            $order->proposal_status_label = self::statusLabel($order->proposal_status, $order->proposal_has_content);
            $order->proposal_signed_pdf_path = $summary->signed_pdf_path ?? null;
            $order->proposal_pending = (string)$order->proposal_status === 'PENDING_APPROVAL'
                || ((int)($order->proposal_requires_approval ?? 0) === 1
                    && !in_array((string)$order->proposal_status, ['APPROVED'], true));
        }
        return $orders;
    }
}
