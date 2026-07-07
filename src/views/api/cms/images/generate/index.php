<?php

use App\Services\CmsImageGenerationService;
use App\Services\LoginService;

header('Content-Type: application/json; charset=utf-8');

$user = LoginService::getSession();
if (!$user || (int)$user->getLevel() !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden.']);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$prompt = trim((string)($_POST['prompt'] ?? ''));
$folder = trim((string)($_POST['folder'] ?? 'cms/generated-images'));
$size = trim((string)($_POST['size'] ?? '1024x1024'));

try {
    $service = new CmsImageGenerationService();
    $image = $service->generateAndUpload($prompt, $folder, $size);
    echo json_encode(['ok' => true, 'image' => $image], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
