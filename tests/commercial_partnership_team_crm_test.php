<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repositories\CommercialPartnershipRepository;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$repository = new CommercialPartnershipRepository();
$suffix = bin2hex(random_bytes(5));
$email = 'qa-team-sponsor-' . $suffix . '@example.org';
$organization = 'VNV Team CRM ' . $suffix;
$opportunityId = 0;

try {
    $opportunityId = $repository->createManualOpportunity(2, 'vnvevents', 'SPONSORSHIP', [
        'organization_name' => $organization,
        'contact_name' => 'Sponsor Contact',
        'contact_role' => 'Partnership Director',
        'email' => $email,
        'phone' => '3055550199',
        'title' => 'Manual sponsor prospect',
        'requested_services' => 'Sponsor introduction and activation',
        'next_follow_up_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i'),
    ], 44, 'Team User', true);

    $repository->db->query('SELECT o.assigned_user_id,o.source,o.title,c.email,c.role_title,
        (SELECT COUNT(*) FROM commercial_consents cs WHERE cs.opportunity_id=o.id) consent_count
        FROM commercial_opportunities o JOIN commercial_contacts c ON c.id=o.contact_id
        WHERE o.id=:id');
    $repository->db->bind(':id', $opportunityId);
    $created = $repository->db->fetchOne();
    if (!$created || (int)$created->assigned_user_id !== 44 || $created->source !== 'manual_crm' || (int)$created->consent_count !== 0) {
        throw new RuntimeException('Manual team CRM creation did not preserve assignment or consent boundaries.');
    }

    $repository->updateOpportunityDetails(2, 'vnvevents', $opportunityId, [
        'organization_name' => $organization,
        'contact_name' => 'Sponsor Contact Updated',
        'contact_role' => 'VP Partnerships',
        'email' => $email,
        'phone' => '3055550200',
        'title' => 'Updated sponsor prospect',
        'requested_services' => 'Updated activation scope',
    ], 44, 44);

    $repository->db->query('SELECT o.title,c.name,c.role_title FROM commercial_opportunities o JOIN commercial_contacts c ON c.id=o.contact_id WHERE o.id=:id');
    $repository->db->bind(':id', $opportunityId);
    $updated = $repository->db->fetchOne();
    if (!$updated || $updated->title !== 'Updated sponsor prospect' || $updated->name !== 'Sponsor Contact Updated' || $updated->role_title !== 'VP Partnerships') {
        throw new RuntimeException('Assigned team member could not update the partnership record.');
    }

    $blocked = false;
    try {
        $repository->updateOpportunityDetails(2, 'vnvevents', $opportunityId, [
            'contact_name' => 'Blocked',
            'email' => $email,
            'title' => 'Blocked',
        ], 45, 45);
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    if (!$blocked) {
        throw new RuntimeException('A different team member was allowed to edit an unassigned partnership record.');
    }

    if ($repository->updateStage(2, 'vnvevents', $opportunityId, 'CONTACTED', 45, 45)) {
        throw new RuntimeException('A different team member was allowed to move an unassigned partnership stage.');
    }
    if (!$repository->updateStage(2, 'vnvevents', $opportunityId, 'CONTACTED', 44, 44)) {
        throw new RuntimeException('The assigned team member could not move the partnership stage.');
    }

    echo 'Commercial partnership team CRM OK: manual creation, scoped editing, stage ownership and consent boundaries verified.' . PHP_EOL;
} finally {
    if ($opportunityId > 0) {
        foreach (['commercial_activities', 'commercial_audit_logs'] as $table) {
            $column = $table === 'commercial_audit_logs' ? 'entity_id' : 'opportunity_id';
            $repository->db->query("DELETE FROM {$table} WHERE {$column}=:id");
            $repository->db->bind(':id', $opportunityId);
            $repository->db->execute();
        }
        $repository->db->query('DELETE FROM commercial_opportunities WHERE id=:id');
        $repository->db->bind(':id', $opportunityId);
        $repository->db->execute();
    }
    $repository->db->query('DELETE FROM commercial_contacts WHERE id_owner=2 AND site_key=\'vnvevents\' AND email=:email');
    $repository->db->bind(':email', $email);
    $repository->db->execute();
    $repository->db->query('DELETE FROM commercial_organizations WHERE id_owner=2 AND site_key=\'vnvevents\' AND name=:name');
    $repository->db->bind(':name', $organization);
    $repository->db->execute();
}
