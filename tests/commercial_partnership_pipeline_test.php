<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repositories\CommercialPartnershipRepository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$repository = new CommercialPartnershipRepository();
$suffix = bin2hex(random_bytes(5));
$opportunityIds = [];
$emails = [];
$organizations = [];

try {
    $emails[] = $sponsorEmail = 'qa-sponsor-' . $suffix . '@example.org';
    $organizations[] = $sponsorOrg = 'VNV QA Sponsor ' . $suffix;
    $sponsorData = [
        'organization_name' => $sponsorOrg,
        'contact_name' => 'QA Sponsor Contact',
        'email' => $sponsorEmail,
        'event_name' => 'Retro Glam',
        'requested_services' => ['Brand partner interest'],
        'consent_text' => 'QA sponsor consent',
    ];
    $opportunityIds[] = $sponsorId = $repository->createPublicOpportunity(2, 'vnvevents', 'SPONSORSHIP', $sponsorData);
    $duplicateId = $repository->createPublicOpportunity(2, 'vnvevents', 'SPONSORSHIP', $sponsorData);
    if ($duplicateId !== $sponsorId) {
        throw new RuntimeException('Thirty-minute sponsor deduplication did not reuse the opportunity.');
    }

    $emails[] = $originalEmail = 'qa-original-' . $suffix . '@example.org';
    $opportunityIds[] = $originalId = $repository->createPublicOpportunity(2, 'vnvevents', 'ORIGINAL', [
        'contact_name' => 'QA Original Contact',
        'email' => $originalEmail,
        'event_name' => 'Mommy and Daddy Break',
        'requested_services' => ['attending'],
        'consent_text' => 'QA original consent',
    ]);

    $repository->db->query('SELECT o.pipeline,o.stage,d.status deal_status,cs.consented FROM commercial_opportunities o JOIN commercial_sponsor_deals d ON d.opportunity_id=o.id JOIN commercial_consents cs ON cs.opportunity_id=o.id WHERE o.id=:id');
    $repository->db->bind(':id', $sponsorId);
    $sponsor = $repository->db->fetchOne();
    $repository->db->query('SELECT pipeline,stage FROM commercial_opportunities WHERE id=:id');
    $repository->db->bind(':id', $originalId);
    $original = $repository->db->fetchOne();
    if (!$sponsor || $sponsor->pipeline !== 'SPONSORSHIP' || $sponsor->stage !== 'INTRO_CONSENTED' || $sponsor->deal_status !== 'INTRO_CONSENTED' || (int)$sponsor->consented !== 1) {
        throw new RuntimeException('Sponsorship pipeline, deal or consent did not persist correctly.');
    }
    if (!$original || $original->pipeline !== 'ORIGINAL_WAITLIST' || $original->stage !== 'WAITLIST') {
        throw new RuntimeException('Original-experience waitlist did not persist correctly.');
    }
    echo 'Commercial pipeline integration OK: deduplication, sponsor deal, consent and original waitlist persisted.' . PHP_EOL;
} finally {
    foreach ($opportunityIds as $id) {
        foreach (['commercial_sponsor_deals', 'commercial_activation_orders', 'commercial_support_grants', 'commercial_commissions', 'commercial_consents', 'commercial_activities'] as $table) {
            $repository->db->query("DELETE FROM {$table} WHERE opportunity_id=:id");
            $repository->db->bind(':id', $id);
            $repository->db->execute();
        }
        $repository->db->query('DELETE FROM commercial_audit_logs WHERE entity_type=\'opportunity\' AND entity_id=:id');
        $repository->db->bind(':id', $id);
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
