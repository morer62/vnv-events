<?php

use App\Services\GourmetDeliveryAreaService;
use App\Services\GourmetExpressService;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;

header('Content-Type: application/json; charset=utf-8');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    return;
}

$payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
$address = trim((string)($payload['address'] ?? ''));
$ownerId = AvomealContext::ownerId();
$siteKey = SiteContext::siteKey();

try {
    (new GourmetExpressService())->assertStoreOpen($ownerId, $siteKey);
    $verified = (new GourmetDeliveryAreaService())->validate($ownerId, $siteKey, $address);
    echo json_encode(['ok' => true, 'address' => $verified], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    $settings = (new GourmetExpressService())->settings($ownerId, $siteKey);
    echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'reopen_at' => $settings['reopen_at'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
