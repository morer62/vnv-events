<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$workspace = file_get_contents($root . '/src/views/templates/components/orderProposalWorkspace.twig');
$calendar = file_get_contents($root . '/src/views/panel/level1/planner-hub/management/orders/calendar/index.twig');
$calendarService = file_get_contents($root . '/src/Services/OrdersCalendarService.php');
$controller = file_get_contents($root . '/src/views/panel/shared/order-proposal/controller.php');
$publicWorkspace = file_get_contents($root . '/src/views/public/commerce/proposal-access/index.twig');
$migration = file_get_contents($root . '/db/migrations/20261116120000_create_order_proposal_approvals.php');

foreach (['insertUnorderedList', 'insertOrderedList', 'proposal-page-break', 'proposalImageInput', 'Approve and sign proposal', 'Reason for reopening', 'signed_pdf_path', 'Client proposal link', 'copyProposalClientLink'] as $needle) {
    $assert(str_contains($workspace, $needle), "Proposal workspace contract missing: {$needle}");
}
foreach (['data-proposal-disclaimer="food"', 'data-proposal-disclaimer="decor"', 'Colors and decorative elements are for reference purposes only.', 'The setup will aim to match the approved design as closely as possible.', 'Editable disclaimer inserted.'] as $needle) {
    $assert(str_contains($workspace, $needle), "Proposal disclaimer helper missing: {$needle}");
}
foreach (['Large title', 'Medium title', '<strong>Bold</strong>', 'rememberSelection', 'restoreSelection'] as $needle) {
    $assert(str_contains($workspace, $needle), "Proposal rich-text formatting control missing: {$needle}");
}
foreach (['Signature preview', 'proposalTypedSignature', 'proposalImageSignature', 'FileReader', 'handwritten-style preview', 'proposalSignButton'] as $needle) {
    $assert(str_contains($workspace, $needle), "Account proposal signature preview missing: {$needle}");
    $assert(str_contains($publicWorkspace, $needle), "Public proposal signature preview missing: {$needle}");
}
foreach (['orders-calendar-print-agenda', 'printable_orders', 'Menu / Proposal', 'calendarOrderProposalLink', 'Signed PDF', 'orders-calendar-proposal-badge--approved', 'data-open-proposal-url'] as $needle) {
    $assert(str_contains($calendar, $needle), "Calendar print/pending contract missing: {$needle}");
}
$assert(str_contains($calendarService, 'Client has not approved the menu yet'), 'Calendar service does not expose the orange pending-approval message.');
$assert(str_contains($calendarService, 'Menu approved by client'), 'Calendar service does not expose the green approved message.');
$assert(!str_contains($workspace, 'Revision and approval history'), 'Internal proposal audit history is still exposed in the account workspace.');
$assert(!str_contains($publicWorkspace, 'Revision and approval history'), 'Internal proposal audit history is still exposed in the secure client view.');
$assert(str_contains($workspace, 'Save and request client approval'), 'Saving details does not clearly publish the current version for client approval.');
$assert(str_contains($workspace, '{{ guest_count }} guests'), 'Proposal workspace does not show the guest count.');
$assert(str_contains($publicWorkspace, '{{ guest_count }} guests'), 'Secure proposal view does not show the guest count.');
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
