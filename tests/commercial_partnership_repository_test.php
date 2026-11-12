<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repositories\CommercialPartnershipRepository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

$repository = new CommercialPartnershipRepository();
$suffix = bin2hex(random_bytes(5));
$email = 'qa-commercial-' . $suffix . '@example.org';
$organization = 'VNV QA Brand ' . $suffix;
$opportunityId = 0;

try {
    $opportunityId = $repository->createPublicOpportunity(2, 'vnvevents', 'ACTIVATION', [
        'brand_name' => $organization,
        'contact_name' => 'VNV QA Automation',
        'email' => $email,
        'phone' => '3055550100',
        'event_name' => 'Commercial CRM Integration Test',
        'event_date' => (new DateTimeImmutable('+90 days'))->format('Y-m-d'),
        'activation_type' => 'Photo moment',
        'dimensions' => '10 x 10 ft',
        'requested_services' => ['Fabrication', 'Installation'],
        'consent_text' => 'Automated test consent.',
        'source' => 'automated-test',
        'utm_source' => 'qa',
        '_remote_ip' => '127.0.0.1',
        '_ip_salt' => 'test',
        '_user_agent' => 'VNV automated repository test',
    ]);

    if ($opportunityId < 1) {
        throw new RuntimeException('The repository did not return an opportunity ID.');
    }

    $repository->db->query('SELECT o.pipeline,o.stage,c.email,a.status activation_status,cs.consented
        FROM commercial_opportunities o
        JOIN commercial_contacts c ON c.id=o.contact_id
        JOIN commercial_activation_orders a ON a.opportunity_id=o.id
        JOIN commercial_consents cs ON cs.opportunity_id=o.id
        WHERE o.id=:id AND o.id_owner=2 AND o.site_key=\'vnvevents\'');
    $repository->db->bind(':id', $opportunityId);
    $row = $repository->db->fetchOne();
    if (!$row || $row->pipeline !== 'ACTIVATION' || $row->stage !== 'BRIEF' || $row->activation_status !== 'BRIEF' || (int)$row->consented !== 1 || $row->email !== $email) {
        throw new RuntimeException('Persisted activation opportunity did not match the expected CRM contract.');
    }

    echo 'Commercial partnership repository integration OK: opportunity, activation brief and consent persisted.' . PHP_EOL;
} finally {
    if ($opportunityId > 0) {
        foreach (['commercial_activation_orders', 'commercial_consents', 'commercial_activities', 'commercial_audit_logs'] as $table) {
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
