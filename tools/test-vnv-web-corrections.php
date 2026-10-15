<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$orders = $read('src/views/panel/level1/planner-hub/management/orders/orders/index.php');
$ordersArchive = $read('src/views/panel/level1/planner-hub/management/orders/orders/previous/index.php');
$ordersTemplate = $read('src/views/panel/level1/planner-hub/management/orders/orders/index.twig');
$ordersArchiveTemplate = $read('src/views/panel/level1/planner-hub/management/orders/orders/previous/index.twig');
$editTemplate = $read('src/views/panel/level1/planner-hub/management/orders/orders/edit/index.twig');
$payments = $read('src/views/panel/level1/planner-hub/management/orders/orders/payments/index.php');
$paymentsTemplate = $read('src/views/panel/level1/planner-hub/management/orders/orders/payments/index.twig');
$gourmet = $read('src/views/public/pages/vnv-gourmet/index.twig');

foreach ([$orders, $ordersArchive] as $controller) {
    $assert(str_contains($controller, '$amountCents > $remainingBeforeCents'), 'Manual advances must compare currency in cents.');
}
foreach ([$ordersTemplate, $ordersArchiveTemplate] as $template) {
    $assert(str_contains($template, "setAttribute('max', remaining.toFixed(2))"), 'Manual advance max must use two-decimal currency.');
}
$assert(str_contains($payments, "delete_manual_advance"), 'Manual advance deletion action is missing.');
$assert(str_contains($paymentsTemplate, 'Delete advance'), 'Manual advance deletion control is missing.');
$assert(str_contains($editTemplate, 'position: fixed;'), 'Desktop service suggestions must escape the responsive table overflow.');
$assert(str_contains($editTemplate, 'input.getBoundingClientRect()'), 'Desktop service suggestions must be positioned against the active input.');
$assert(str_contains($editTemplate, 'item.addEventListener("pointerdown", chooseService)'), 'Desktop service selection must be committed before input blur.');
$assert(str_contains($editTemplate, 'function selectExactService(input)'), 'Exact service names must resolve to a service id.');
$assert(str_contains($editTemplate, "event.key !== 'Enter'"), 'Desktop service selection must support the Enter key.');
$assert(str_contains($editTemplate, "getElementById('quoteEditForm')?.addEventListener('submit'"), 'Quote save must resolve or reject incomplete service selections.');
$assert(str_contains($gourmet, 'Bacon, sausages, roasted potatoes and pastries available as add-ons'), 'Brunch add-on copy is missing.');
$brunchUpdater = $read('bin/apply-vnv-web-corrections.php');
$assert(str_contains($brunchUpdater, 'Every station includes scrambled eggs, fresh bread,veggies and syrops. Bacon, sausages, roasted potatoes, and pastries are optional add-ons available upon request.'), 'Brunch product copy must match the approved wording exactly.');
$payrollController = $read('src/views/panel/level1/planner-hub/management/payroll/pending/details/index.php');
$payrollTemplate = $read('src/views/panel/level1/planner-hub/management/payroll/pending/details/index.twig');
$payrollEmail = $read('src/views/templates/emails/payroll_payment_confirmation.php');
$assert(str_contains($payrollController, 'foreach ($selectedHours as $h)'), 'Payroll totals must use only selected sessions.');
$assert(!str_contains($payrollController, 'foreach ($unpaidHours as $h)'), 'Payroll must not total every unpaid session.');
$assert(str_contains($payrollController, '"id_owner" => (int)$currentOwnerId'), 'Paid-hour updates must remain scoped to the active owner.');
$assert(str_contains($payrollTemplate, 'data-duration-seconds='), 'Payroll selection must expose each selected duration.');
$assert(str_contains($payrollTemplate, "selectedAmount.toFixed(2)"), 'Payroll must show the selected amount before payment.');
$assert(str_contains($payrollEmail, 'Sessions Included in This Payment:'), 'Payroll confirmation must list the sessions actually paid.');

echo "VNV web correction contracts passed.\n";
