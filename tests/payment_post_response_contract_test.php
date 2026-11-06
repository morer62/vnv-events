<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\PostResponseTaskService;

function assertPaymentContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$runs = 0;
PostResponseTaskService::enqueue(static function () use (&$runs): void {
    $runs++;
});

assertPaymentContract(PostResponseTaskService::pendingCount() === 1, 'post-response work is queued');
PostResponseTaskService::runPending(false);
assertPaymentContract($runs === 1, 'queued work runs exactly once');
assertPaymentContract(PostResponseTaskService::pendingCount() === 0, 'queue is empty after execution');
PostResponseTaskService::runPending(false);
assertPaymentContract($runs === 1, 're-running an empty queue is idempotent');

$root = dirname(__DIR__);
$handlers = [
    $root . '/src/views/public/commerce/order-access/full/index.php',
    $root . '/src/views/public/commerce/order-access/first/index.php',
    $root . '/src/views/public/commerce/order-access/second/index.php',
];

foreach ($handlers as $handler) {
    $source = (string)file_get_contents($handler);
    assertPaymentContract(
        substr_count($source, 'PaymentNotificationService::generatePaymentNotifications($orderId, true)') === 1,
        basename(dirname($handler)) . ' defers payment email exactly once'
    );
    assertPaymentContract(
        !str_contains($source, 'PaymentNotificationService::generatePaymentNotifications($orderId);'),
        basename(dirname($handler)) . ' has no synchronous payment-email call'
    );
    assertPaymentContract(
        str_contains($source, 'PostResponseTaskService::enqueue'),
        basename(dirname($handler)) . ' also defers remote push delivery'
    );
}

$notificationService = (string)file_get_contents($root . '/src/Services/PaymentNotificationService.php');
assertPaymentContract(
    str_contains($notificationService, 'PostResponseTaskService::enqueue'),
    'payment email is placed on the post-response queue'
);

$emailService = (string)file_get_contents($root . '/src/Services/EmailService.php');
$panelPosition = strpos($emailService, "['source' => 'panel'");
$envPosition = strpos($emailService, "['source' => 'env'");
assertPaymentContract(
    $panelPosition !== false && $envPosition !== false && $panelPosition < $envPosition,
    'Level 1 panel SMTP remains primary and .env remains fallback only'
);

fwrite(STDOUT, "PASS payment post-response contract\n");
