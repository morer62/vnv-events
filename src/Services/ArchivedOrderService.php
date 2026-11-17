<?php

namespace App\Services;

use App\Repositories\Connection;
use App\Utils\FileUtils;
use RuntimeException;
use Throwable;

final class ArchivedOrderService
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? new Connection();
    }

    public static function confirmationCode(int $orderId): string
    {
        return 'DELETE VNV341' . $orderId;
    }

    public function archive(int $orderId, int $ownerId): bool
    {
        $this->db->query('UPDATE orders SET is_archived = 1, archived_at = NOW() WHERE id = :id AND id_owner = :owner AND is_archived = 0');
        $this->db->bind(':id', $orderId);
        $this->db->bind(':owner', $ownerId);
        $this->db->execute();
        return $this->db->rowCount() === 1;
    }

    public function unarchive(int $orderId, int $ownerId): bool
    {
        $this->db->query('UPDATE orders SET is_archived = 0, archived_at = NULL WHERE id = :id AND id_owner = :owner AND is_archived = 1');
        $this->db->bind(':id', $orderId);
        $this->db->bind(':owner', $ownerId);
        $this->db->execute();
        return $this->db->rowCount() === 1;
    }

    public function permanentlyDelete(int $orderId, int $ownerId, string $confirmation): void
    {
        if (!hash_equals(self::confirmationCode($orderId), trim($confirmation))) {
            throw new RuntimeException('The confirmation text does not match this order.');
        }

        $this->db->query('SELECT id FROM orders WHERE id = :id AND id_owner = :owner AND is_archived = 1 LIMIT 1');
        $this->db->bind(':id', $orderId);
        $this->db->bind(':owner', $ownerId);
        if (!$this->db->fetchOne()) {
            throw new RuntimeException('Only an archived order from this business can be permanently deleted.');
        }

        $paths = $this->filePaths($orderId);
        $this->db->beginTransaction();
        try {
            $this->deleteProposalRecords($orderId);
            $this->deleteInvoiceRecords($orderId);
            $this->deleteSuborderRecords($orderId);

            foreach ([
                ['manager_assignment_history', 'order_id'],
                ['orders_acceptance_contracts', 'id_order'],
                ['orders_advances', 'id_order'],
                ['orders_closure_communications', 'id_order'],
                ['orders_files', 'id_order'],
                ['orders_payments', 'id_order'],
                ['orders_payment_split', 'id_order'],
                ['orders_services_assigned', 'id_order'],
                ['orders_services_notes', 'id_order'],
                ['orders_services_shopping_list', 'id_order'],
                ['orders_staff_invites', 'id_order'],
                ['orders_status_history', 'id_order'],
                ['orders_team_order_photos', 'id_order'],
                ['orders_team_tasks', 'id_order'],
                ['order_execution_files', 'id_order'],
                ['order_remaining_amounts', 'id_order'],
                ['order_service_menu_selections', 'id_order'],
                ['payments_received_all', 'id_order'],
                ['reminder_logs', 'order_id'],
                ['gourmet_conversion_exports', 'order_id'],
                ['document_logs', 'id_order'],
                ['event_execution_spaces', 'id_order'],
            ] as [$table, $column]) {
                $this->deleteFromIfPresent($table, $column, $orderId);
            }

            foreach ([
                ['affiliate_commissions', 'order_id'],
                ['authorized_manual_charge_logs', 'order_id'],
                ['commercial_projects', 'order_id'],
                ['crm_followups', 'id_order'],
            ] as [$table, $column]) {
                $this->nullReferenceIfPresent($table, $column, $orderId);
            }

            $this->db->query('DELETE FROM orders WHERE id = :id AND id_owner = :owner AND is_archived = 1');
            $this->db->bind(':id', $orderId);
            $this->db->bind(':owner', $ownerId);
            $this->db->execute();
            if ($this->db->rowCount() !== 1) {
                throw new RuntimeException('The archived order could not be deleted.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        foreach (array_unique(array_filter($paths)) as $path) {
            try {
                FileUtils::removeFile((string)$path);
            } catch (Throwable) {
                // The database deletion is authoritative; missing legacy files are safe to ignore.
            }
        }
    }

    private function deleteProposalRecords(int $orderId): void
    {
        if (!$this->tableExists('order_proposals')) {
            return;
        }
        $this->db->query('SELECT id FROM order_proposals WHERE id_order = :id');
        $this->db->bind(':id', $orderId);
        foreach ($this->db->fetchAll() as $proposal) {
            foreach (['order_proposal_audit_logs', 'order_proposal_approvals', 'order_proposal_revisions'] as $table) {
                $this->deleteFromIfPresent($table, 'id_proposal', (int)$proposal->id);
            }
        }
        $this->deleteFromIfPresent('order_proposals', 'id_order', $orderId);
    }

    private function deleteInvoiceRecords(int $orderId): void
    {
        if (!$this->tableExists('invoices')) {
            return;
        }
        $this->db->query('SELECT id FROM invoices WHERE id_order = :id');
        $this->db->bind(':id', $orderId);
        foreach ($this->db->fetchAll() as $invoice) {
            $this->deleteFromIfPresent('commercial_invoice_links', 'invoice_id', (int)$invoice->id);
        }
        $this->deleteFromIfPresent('invoices', 'id_order', $orderId);
    }

    private function deleteSuborderRecords(int $orderId): void
    {
        if (!$this->tableExists('orders_suborder')) {
            return;
        }
        $this->db->query('SELECT id FROM orders_suborder WHERE id_order = :id');
        $this->db->bind(':id', $orderId);
        foreach ($this->db->fetchAll() as $suborder) {
            foreach ([
                'orders_advances', 'orders_payments', 'orders_services_notes', 'orders_services_shopping_list',
                'orders_suborder_staff_invites', 'orders_team_tasks', 'order_service_menu_selections',
                'order_suborder_services_assigned', 'payments_received_all', 'suborder_remaining_amounts',
            ] as $table) {
                $this->deleteFromIfPresent($table, 'id_suborder', (int)$suborder->id);
            }
        }
        $this->deleteFromIfPresent('orders_suborder', 'id_order', $orderId);
    }

    private function filePaths(int $orderId): array
    {
        $paths = [];
        foreach ([
            ['orders_files', 'file_path'],
            ['order_execution_files', 'file_path'],
            ['document_logs', 'file_path'],
            ['orders_acceptance_contracts', 'file_path'],
            ['orders_acceptance_contracts', 'signature_image_path'],
            ['orders_team_order_photos', 'photo_url'],
        ] as [$table, $column]) {
            if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
                continue;
            }
            $this->db->query("SELECT `{$column}` AS path FROM `{$table}` WHERE id_order = :id");
            $this->db->bind(':id', $orderId);
            foreach ($this->db->fetchAll() as $row) {
                if (!empty($row->path)) {
                    $paths[] = (string)$row->path;
                }
            }
        }
        return $paths;
    }

    private function deleteFromIfPresent(string $table, string $column, int $value): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return;
        }
        $this->db->query("DELETE FROM `{$table}` WHERE `{$column}` = :value");
        $this->db->bind(':value', $value);
        $this->db->execute();
    }

    private function nullReferenceIfPresent(string $table, string $column, int $value): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return;
        }
        $this->db->query("UPDATE `{$table}` SET `{$column}` = NULL WHERE `{$column}` = :value");
        $this->db->bind(':value', $value);
        $this->db->execute();
    }

    private function tableExists(string $table): bool
    {
        $this->db->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1');
        $this->db->bind(':table', $table);
        return (bool)$this->db->fetchOne();
    }

    private function columnExists(string $table, string $column): bool
    {
        $this->db->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1');
        $this->db->bind(':table', $table);
        $this->db->bind(':column', $column);
        return (bool)$this->db->fetchOne();
    }
}
