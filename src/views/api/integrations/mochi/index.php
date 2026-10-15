<?php

use App\Repositories\OrdersServiceRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$authenticate = static function (): ?JsonResponse {
    $secretFile = $_ENV['MOCHI_VNV_SECRET_FILE'] ?? '/home/ophyra/.mochi-vnv-secret';
    $timestamp = (string)($_SERVER['HTTP_X_MOCHI_TIMESTAMP'] ?? '');
    $provided = strtolower((string)($_SERVER['HTTP_X_MOCHI_SIGNATURE'] ?? ''));
    if (!is_readable($secretFile) || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 || !preg_match('/^[a-f0-9]{64}$/', $provided)) {
        return new JsonResponse(['error' => 'integration_authentication_required'], 401);
    }
    $secret = trim((string)file_get_contents($secretFile));
    $body = (string)file_get_contents('php://input');
    $expected = hash_hmac('sha256', $timestamp."\n".$body, $secret);
    if (!hash_equals($expected, $provided)) return new JsonResponse(['error' => 'invalid_integration_signature'], 401);
    return null;
};

$router = new Router();
$router->get(static function () use ($authenticate) {
    if ($error = $authenticate()) return $error;
    $ownerId = max(1, (int)($_ENV['VNV_EVENTS_OWNER_ID'] ?? 2));
    $action = (string)($_GET['action'] ?? 'health');
    if ($action === 'health') return new JsonResponse(['status' => 'ok', 'service' => 'vnv-events-commercial-api']);
    if ($action !== 'catalog') return new JsonResponse(['error' => 'unsupported_action'], 404);
    $services = (new OrdersServiceRepository())->getAllByInstitutionOwner($ownerId);
    $items = array_map(static fn(object $service): array => [
        'id' => (int)$service->id,
        'name' => (string)$service->name,
        'description' => (string)($service->description ?? ''),
        'price' => round((float)($service->price ?? 0), 2),
        'variable_price' => strtoupper((string)($service->is_variable ?? 'NO')) === 'YES',
    ], $services);
    return new JsonResponse(['items' => $items, 'count' => count($items), 'source' => 'VNV Events']);
});
$router->run();
