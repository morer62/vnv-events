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
$assert(str_contains($gourmet, 'Bacon, roasted potatoes and pastries available as add-ons'), 'Brunch add-on copy is missing.');

echo "VNV web correction contracts passed.\n";
