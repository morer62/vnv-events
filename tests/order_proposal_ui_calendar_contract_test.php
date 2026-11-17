<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$workspace = file_get_contents($root . '/src/views/templates/components/orderProposalWorkspace.twig');
$calendar = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/calendar/index.twig');
$controller = file_get_contents($root . '/src/views/panel/shared/order-proposal/controller.php');
$migration = file_get_contents($root . '/db/migrations/20261116120000_create_order_proposal_approvals.php');

foreach (['insertUnorderedList', 'insertOrderedList', 'proposal-page-break', 'proposalImageInput', 'Approve and sign proposal', 'Reason for reopening', 'signed_pdf_path', 'Client proposal link', 'copyProposalClientLink'] as $needle) {
    $assert(str_contains($workspace, $needle), "Proposal workspace contract missing: {$needle}");
}
foreach (['orders-calendar-print-agenda', 'printable_orders', 'Menu / Proposal', 'calendarOrderProposalLink', 'Signed PDF'] as $needle) {
    $assert(str_contains($calendar, $needle), "Calendar print/pending contract missing: {$needle}");
}
foreach (['save_draft', 'send_for_approval', 'approve', 'revoke', 'upload_image'] as $action) {
    $assert(str_contains($controller, $action), "Proposal route action missing: {$action}");
}
$teamRoute = file_get_contents($root . '/src/views/panel/level4/planner-hub/team/orders/orders/proposal/index.php');
$teamWork = file_get_contents($root . '/src/views/panel/level4/planner-hub/team/my-work/index.twig');
foreach (['proposalTeamMode', 'controller.php'] as $needle) {
    $assert(str_contains($teamRoute, $needle), "Team proposal route contract missing: {$needle}");
}
foreach (['Menu / Proposal', 'No details yet', 'Signed PDF'] as $needle) {
    $assert(str_contains($teamWork, $needle), "Team proposal visibility contract missing: {$needle}");
}
foreach (['order_proposal_revisions', 'order_proposal_approvals', 'order_proposal_audit_logs', 'proposal_requires_approval'] as $table) {
    $assert(str_contains($migration, $table), "Proposal persistence contract missing: {$table}");
}

echo "PASS proposal UI, history and complete-calendar-print contracts\n";
