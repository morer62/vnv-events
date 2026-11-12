<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repositories\CommercialPartnershipRepository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$repository = new CommercialPartnershipRepository();
$suffix = bin2hex(random_bytes(5));
$ids = [];
$emails = [];
$organizations = [];

try {
    $emails[] = $nonprofitEmail = 'qa-nonprofit-' . $suffix . '@example.org';
    $organizations[] = $nonprofitOrg = 'VNV QA Nonprofit ' . $suffix;
    $ids[] = $nonprofitId = $repository->createPublicOpportunity(2, 'vnvevents', 'NONPROFIT', [
        'organization_name' => $nonprofitOrg,
        'contact_name' => 'QA Nonprofit Contact',
        'email' => $nonprofitEmail,
        'event_name' => 'QA Gala',
        'consent_text' => 'QA consent',
    ]);
    $repository->approveSupportGrant(2, 'vnvevents', $nonprofitId, [
        'consultation_hours' => 3.5,
        'discount_food_staff_percent' => 40,
        'discount_decor_percent' => 70,
        'approved_scope' => 'QA-approved selected support.',
    ], 1);

    $emails[] = $corporateEmail = 'qa-corporate-' . $suffix . '@example.org';
    $ids[] = $corporateId = $repository->createPublicOpportunity(2, 'vnvevents', 'CORPORATE', [
        'contact_name' => 'QA Corporate Contact',
        'email' => $corporateEmail,
        'event_name' => 'QA Corporate Event',
        'consent_text' => 'QA consent',
    ]);
    $commission = $repository->recordCommission(2, 'vnvevents', $corporateId, 'CORPORATE_FEE', 'QA-' . $suffix, 1000, 1);
    $repository->approveCommission(2, 'vnvevents', (int)$commission['id'], 1);

    if ((float)$commission['eligible_amount'] !== 1000.0) {
        throw new RuntimeException('Eligible corporate fee was not preserved.');
    }
    $repository->db->query('SELECT sg.status,sg.consultation_hours,sg.discount_food_staff_percent,sg.discount_decor_percent,c.eligible_collected_amount,c.status commission_status
        FROM commercial_support_grants sg
        JOIN commercial_commissions c ON c.opportunity_id=:corporate
        WHERE sg.opportunity_id=:nonprofit LIMIT 1');
    $repository->db->bind(':corporate', $corporateId);
    $repository->db->bind(':nonprofit', $nonprofitId);
    $row = $repository->db->fetchOne();
    if (!$row || $row->status !== 'APPROVED' || (float)$row->consultation_hours !== 3.5 || (float)$row->discount_food_staff_percent !== 40.0 || (float)$row->discount_decor_percent !== 70.0 || (float)$row->eligible_collected_amount !== 1000.0 || $row->commission_status !== 'APPROVED') {
        throw new RuntimeException('Finance controls were not persisted as expected.');
    }
    echo 'Commercial finance repository integration OK: support approval, commission basis and audit persisted.' . PHP_EOL;
} finally {
    foreach ($ids as $id) {
        $repository->db->query('SELECT id FROM commercial_commissions WHERE opportunity_id=:id');
        $repository->db->bind(':id', $id);
        $commissionRows = $repository->db->fetchAll();
        foreach ($commissionRows as $commissionRow) {
            $repository->db->query('DELETE FROM commercial_audit_logs WHERE entity_type=\'commission\' AND entity_id=:commission');
            $repository->db->bind(':commission', (int)$commissionRow->id);
            $repository->db->execute();
        }
        foreach (['commercial_activation_orders', 'commercial_support_grants', 'commercial_commissions', 'commercial_consents', 'commercial_activities'] as $table) {
            $repository->db->query("DELETE FROM {$table} WHERE opportunity_id=:id");
            $repository->db->bind(':id', $id);
            $repository->db->execute();
        }
        $repository->db->query('DELETE FROM commercial_audit_logs WHERE entity_id=:id OR (entity_type=\'commission\' AND after_json LIKE :needle)');
        $repository->db->bind(':id', $id);
        $repository->db->bind(':needle', '%QA-' . $suffix . '%');
        $repository->db->execute();
        $repository->db->query('DELETE FROM commercial_opportunities WHERE id=:id');
        $repository->db->bind(':id', $id);
        $repository->db->execute();
    }
    foreach ($emails as $email) {
        $repository->db->query('DELETE FROM commercial_contacts WHERE id_owner=2 AND site_key=\'vnvevents\' AND email=:email');
        $repository->db->bind(':email', $email);
        $repository->db->execute();
    }
    foreach ($organizations as $organization) {
        $repository->db->query('DELETE FROM commercial_organizations WHERE id_owner=2 AND site_key=\'vnvevents\' AND name=:name');
        $repository->db->bind(':name', $organization);
        $repository->db->execute();
    }
}
