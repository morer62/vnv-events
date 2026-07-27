<?php

use App\Repositories\AiAgentsRepository;
use App\Services\AiAgentExecutionService;

header('Content-Type: application/json; charset=utf-8');

function agentWebhookResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    agentWebhookResponse(['ok' => false, 'error' => 'POST required.'], 405);
}

$key = trim((string)($_GET['agent'] ?? ''));
$ownerId = max(1, (int)($_SERVER['HTTP_X_VNV_OWNER'] ?? 2));
$token = trim((string)($_SERVER['HTTP_X_VNV_AGENT_SECRET'] ?? ''));
if ($key === '' || $token === '') {
    agentWebhookResponse(['ok' => false, 'error' => 'Agent and signed secret are required.'], 401);
}

$repository = new AiAgentsRepository();
if (!$repository->storageReady()) {
    agentWebhookResponse(['ok' => false, 'error' => 'Agent storage is not installed.'], 503);
}
$agent = $repository->find($ownerId, $key);
if (!$agent || !$agent->webhook_token_hash || !hash_equals((string)$agent->webhook_token_hash, hash('sha256', $token))) {
    agentWebhookResponse(['ok' => false, 'error' => 'Invalid agent secret.'], 401);
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
$input = is_array($input) ? $input : [];

try {
    $result = (new AiAgentExecutionService($repository))->run($agent, $ownerId, 0, 'WEBHOOK', $input);
    agentWebhookResponse(['ok' => true] + $result);
} catch (\Throwable $error) {
    agentWebhookResponse(['ok' => false, 'error' => $error->getMessage()], 400);
}
