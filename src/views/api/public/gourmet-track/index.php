<?php

use App\Repositories\LeadIntakeRepository;
use App\Services\GourmetLandingAttributionService;
use App\Utils\AvomealContext;
use App\Utils\Router;

$router = new Router();
$router->post(function (): void {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        return;
    }

    $savedCsrf = (string)($_SESSION['csrf_token'] ?? '');
    $postedCsrf = (string)($data['csrf_token'] ?? '');
    if ($savedCsrf === '' || $postedCsrf === '' || !hash_equals($savedCsrf, $postedCsrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        return;
    }

    $now = time();
    $clicks = array_values(array_filter((array)($_SESSION['gourmet_click_attempts'] ?? []), fn($stamp) => (int)$stamp > $now - 300));
    if (count($clicks) >= 12) {
        http_response_code(429);
        echo json_encode(['ok' => false]);
        return;
    }
    $clicks[] = $now;
    $_SESSION['gourmet_click_attempts'] = $clicks;

    $service = preg_replace('/[^a-z-]/', '', strtolower((string)($data['service'] ?? '')));
    $refCode = mb_substr(preg_replace('/[^A-Za-z0-9-]/', '', (string)($data['ref_code'] ?? '')), 0, 40);
    if ($service === '' || $refCode === '') {
        http_response_code(422);
        echo json_encode(['ok' => false]);
        return;
    }

    $payload = [
        'event' => 'whatsapp_click',
        'ref_code' => $refCode,
        'service' => $service,
        'landing_url' => GourmetLandingAttributionService::safeLandingPath((string)($data['landing_url'] ?? '')),
        'attribution' => GourmetLandingAttributionService::sanitize($data),
        'is_test' => (string)($data['vnv_test'] ?? '') === '1',
        'conversion_time' => GourmetLandingAttributionService::conversionTime(),
    ];
    try {
        (new LeadIntakeRepository())->upsert(AvomealContext::ownerId(), [
            'source' => 'gourmet_whatsapp_click',
            'external_id' => 'gourmet-whatsapp-' . $refCode,
            'channel' => 'whatsapp',
            'contact_name' => null, 'email' => null, 'phone' => null,
            'service_requested' => $service, 'guest_count' => null,
            'venue' => null, 'event_date' => null, 'start_time' => null,
            'end_time' => null, 'setup_minutes' => 0,
            'availability_status' => 'CLICK_ONLY',
            'suggested_manager_id' => null, 'availability_checked_at' => null,
            'status' => $payload['is_test'] ? 'TEST' : 'NEW',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('Gourmet WhatsApp click tracking failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false]);
        return;
    }
    echo json_encode(['ok' => true]);
});
$router->run();
