<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repositories\LeadIntakeRepository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$repository = new LeadIntakeRepository();
$suffix = bin2hex(random_bytes(5));
$externalId = 'qa-quick-request-' . $suffix;
$leadId = 0;

try {
    $payload = [
        'request_type' => 'NONPROFIT',
        'organization_name' => 'VNV QA Nonprofit ' . $suffix,
        'project_brief' => 'Responsive quick-request CRM persistence test.',
        'consent' => true,
    ];
    $leadId = $repository->upsert(2, [
        'source' => 'public_quick_request',
        'external_id' => $externalId,
        'channel' => 'website',
        'contact_name' => 'VNV Quick Request QA',
        'email' => 'qa-quick-' . $suffix . '@example.org',
        'phone' => null,
        'service_requested' => 'Nonprofit support application',
        'guest_count' => null,
        'venue' => 'VNV QA Nonprofit ' . $suffix,
        'event_date' => null,
        'start_time' => null,
        'end_time' => null,
        'setup_minutes' => 60,
        'availability_status' => 'NEEDS_MANUAL_REVIEW',
        'suggested_manager_id' => null,
        'availability_checked_at' => null,
        'status' => 'NEW',
        'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);

    $row = $repository->find(2, $leadId);
    if (!$row || $row->source !== 'public_quick_request' || $row->status !== 'NEW' || $row->availability_status !== 'NEEDS_MANUAL_REVIEW') {
        throw new RuntimeException('Quick request did not persist in the existing Lead Intake pipeline.');
    }
    $stored = json_decode((string)$row->payload_json, true);
    if (($stored['request_type'] ?? null) !== 'NONPROFIT' || empty($stored['consent'])) {
        throw new RuntimeException('Quick request payload or consent did not persist.');
    }

    echo 'Quick request Lead Intake integration OK: request, consent and review status persisted.' . PHP_EOL;
} finally {
    if ($leadId > 0) {
        $repository->db->query('DELETE FROM lead_intake WHERE id_owner=2 AND id=:id AND external_id=:external');
        $repository->db->bind(':id', $leadId);
        $repository->db->bind(':external', $externalId);
        $repository->db->execute();
    }
}
