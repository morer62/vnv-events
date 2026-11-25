<?php

use App\Services\BreakClubService;
use App\Utils\SiteContext;
use Stripe\Webhook;

if (SiteContext::siteKey() !== 'vnvevents') { http_response_code(404); return; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); return; }
$secret = trim((string)($_ENV['VNV_BREAK_CLUB_STRIPE_WEBHOOK_SECRET'] ?? ''));
if ($secret === '') { http_response_code(503); return; }
try {
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) { http_response_code(413); return; }
    $event = Webhook::constructEvent($raw, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret);
} catch (Throwable $error) {
    http_response_code(400); return;
}
if (in_array($event->type, ['checkout.session.completed','checkout.session.async_payment_succeeded','charge.refunded'],true)) {
    $metadata = $event->data->object->metadata;
    if (($metadata->vnv_program ?? '') === 'break_club' && (int)($metadata->break_owner_id ?? 0) === SiteContext::businessOwnerId()) {
        try {
            (new BreakClubService())->providerNotification((string)$event->account,(int)$metadata->break_registration_id);
        } catch (Throwable $error) {
            error_log('Break Club webhook reconciliation failed (' . get_class($error) . ').');
            http_response_code(503); return;
        }
    }
}
http_response_code(204);
