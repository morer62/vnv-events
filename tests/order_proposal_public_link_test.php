<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\OrderProposalAccessService;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$service = new OrderProposalAccessService();
$fixture = (object)[
    'id' => 9182,
    'id_client' => 441,
    'event_date' => date('Y-m-d', strtotime('+30 days')),
];
$token = $service->createToken($fixture);
$payload = $service->validateToken($token);

$assert((int)$payload['order_id'] === 9182, 'Proposal token changed the order identity.');
$assert((int)$payload['user_id'] === 441, 'Proposal token changed the client identity.');
$assert((string)$payload['scope'] === 'order_proposal', 'Proposal token has the wrong scope.');
$assert((int)$payload['exp'] > strtotime('+179 days'), 'Proposal link is not long-lived enough for manual delivery.');
$assert(str_contains($service->urlForOrder($fixture), '/proposal-access?token='), 'Proposal URL does not use the public access route.');

$tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');
$rejected = false;
try {
    $service->validateToken($tampered);
} catch (InvalidArgumentException) {
    $rejected = true;
}
$assert($rejected, 'A tampered proposal token was accepted.');

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/src/views/public/commerce/proposal-access/index.php');
$template = (string)file_get_contents($root . '/src/views/public/commerce/proposal-access/index.twig');
$workspace = (string)file_get_contents($root . '/src/views/templates/components/orderProposalWorkspace.twig');
$workflow = (string)file_get_contents($root . '/src/Services/OrderProposalWorkflowService.php');

foreach (['validateToken', 'proposal_requires_approval', "action === 'approve'", "action === 'revoke'", 'proposal_public_csrf_token'] as $needle) {
    $assert(str_contains($controller, $needle), "Public proposal controller contract missing: {$needle}");
}
foreach (['noindex,nofollow,noarchive', 'Approve and sign proposal', 'Reopen approval', 'content_html|raw'] as $needle) {
    $assert(str_contains($template, $needle), "Public proposal template contract missing: {$needle}");
}
foreach (['Client proposal link', 'Copy link', 'client_access_url'] as $needle) {
    $assert(str_contains($workspace, $needle), "Manager proposal link control missing: {$needle}");
}
foreach (['emailProposalReady', 'Review and sign proposal', 'sendSimpleEmail'] as $needle) {
    $assert(str_contains($workflow, $needle), "Proposal ready email contract missing: {$needle}");
}
for ($level = 1; $level <= 4; $level++) {
    $orderListController = (string)file_get_contents($root . "/src/views/panel/level{$level}/planner-hub/management/orders/orders/index.php");
    $orderListTemplate = (string)file_get_contents($root . "/src/views/panel/level{$level}/planner-hub/management/orders/orders/index.twig");
    $assert(str_contains($orderListController, 'proposal_access_url'), "Level {$level} order list does not generate the proposal URL.");
    $assert(str_contains($orderListTemplate, 'Copy Proposal Link'), "Level {$level} order list does not expose the proposal copy action.");
    $assert(str_contains($orderListTemplate, 'Open Proposal'), "Level {$level} order list does not expose the proposal open action.");
}

echo "PASS secure public proposal link, manual sharing and ready-email contracts\n";
