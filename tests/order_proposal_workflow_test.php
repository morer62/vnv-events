<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\OrderProposalRepository;
use App\Services\OrderProposalContentService;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$content = new OrderProposalContentService();
$sanitized = $content->sanitize('<h2 onclick="bad()">Menu</h2><ul><li>Starter</li><li>Main</li></ul><script>alert(1)</script><img src="javascript:bad" onerror="bad()"><div class="proposal-page-break" data-page-break="true">bad</div><p>Finish</p>');
$assert(str_contains($sanitized, '<h2>Menu</h2>'), 'Heading formatting was not preserved.');
$assert(str_contains($sanitized, '<ul><li>Starter</li><li>Main</li></ul>'), 'List continuity markup was not preserved.');
$assert(str_contains($sanitized, 'proposal-page-break'), 'Page break was not preserved.');
$assert(!str_contains($sanitized, '<script'), 'Script markup was not removed.');
$assert(!str_contains($sanitized, 'onclick'), 'Event attributes were not removed.');
$assert(!str_contains($sanitized, 'javascript:'), 'Unsafe image URLs were not removed.');
$assert(OrderProposalRepository::statusLabel('NOT_STARTED', false) === 'No details yet', 'Empty proposal status is not user friendly.');

$pdo = new PDO($_ENV['DATABASE_URL']);
$order = $pdo->query('SELECT o.id, o.id_owner FROM orders o LEFT JOIN order_proposals p ON p.id_order = o.id WHERE p.id IS NULL ORDER BY o.id DESC LIMIT 1')->fetch(PDO::FETCH_OBJ);
if (!$order) {
    echo "SKIP repository workflow: no unused local order fixture available.\n";
    exit(0);
}

$repository = new OrderProposalRepository();
$proposal = $repository->getOrCreate((int)$order->id, (int)$order->id_owner, (int)$order->id_owner);
try {
    $revisionId = $repository->createRevision((int)$proposal->id, $sanitized, $content->toPlainText($sanitized), 'Automated local workflow test', (int)$order->id_owner);
    $repository->addAudit((int)$proposal->id, $revisionId, 'REVISION_SAVED', (int)$order->id_owner, 'Automated local workflow test');
    $repository->setStatus((int)$proposal->id, 'PENDING_APPROVAL', (int)$order->id_owner);
    $loaded = $repository->getByOrder((int)$order->id);
    $assert($loaded && (int)$loaded->current_revision_id === $revisionId, 'Current revision was not linked.');
    $assert((string)$loaded->status === 'PENDING_APPROVAL', 'Pending approval status was not persisted.');
    $repository->addApproval([
        'id_proposal' => (int)$proposal->id,
        'id_revision' => $revisionId,
        'action' => 'APPROVED',
        'actor_user_id' => (int)$order->id_owner,
        'signature_method' => 'initials',
        'signature_value' => 'QA',
        'signed_pdf_path' => 'https://example.invalid/signed-copy.pdf',
        'content_hash' => hash('sha256', 'test-copy'),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Automated test',
        'user_local_timestamp' => date('Y-m-d H:i:s'),
    ]);
    $repository->setStatus((int)$proposal->id, 'APPROVED', (int)$order->id_owner, $revisionId);
    $summaries = $repository->getSummariesByOrderIds([(int)$order->id]);
    $summary = $summaries[(int)$order->id] ?? null;
    $assert($summary && (string)$summary->status === 'APPROVED', 'Approved proposal summary was not available to order/calendar views.');
    $assert((string)$summary->signed_pdf_path === 'https://example.invalid/signed-copy.pdf', 'Signed PDF was not exposed through the proposal summary.');
    $assert(OrderProposalRepository::statusLabel('APPROVED', true) === 'Approved and signed', 'Approved proposal status label changed unexpectedly.');
    $repository->addApproval([
        'id_proposal' => (int)$proposal->id,
        'id_revision' => $revisionId,
        'action' => 'REVOKED',
        'actor_user_id' => (int)$order->id_owner,
        'reason' => 'Correction requested by automated QA',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Automated test',
        'user_local_timestamp' => date('Y-m-d H:i:s'),
    ]);
    $repository->setStatus((int)$proposal->id, 'CHANGES_REQUESTED', (int)$order->id_owner);
    $history = $repository->getHistory((int)$proposal->id);
    $assert(count($history) >= 3, 'Revision, approval and revocation history was not retained.');
    echo "PASS order proposal workflow and sanitizer\n";
} finally {
    $pdo->prepare('DELETE FROM order_proposal_audit_logs WHERE id_proposal = ?')->execute([(int)$proposal->id]);
    $pdo->prepare('DELETE FROM order_proposal_approvals WHERE id_proposal = ?')->execute([(int)$proposal->id]);
    $pdo->prepare('DELETE FROM order_proposal_revisions WHERE id_proposal = ?')->execute([(int)$proposal->id]);
    $pdo->prepare('DELETE FROM order_proposals WHERE id = ?')->execute([(int)$proposal->id]);
}
