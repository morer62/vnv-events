<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Repositories\Connection;
use App\Services\AiContentAssistantService;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$siteKey = strtolower(trim((string)($_ENV['AI_CONTENT_SITE_KEY'] ?? 'vnv_events')));

try {
    $service = new AiContentAssistantService(new Connection());
    $result = $service->generateDaily($siteKey, null);

    echo '[' . date('Y-m-d H:i:s') . '] ' . $result['message'] . PHP_EOL;
    echo 'Created: ' . (int)$result['created'] . PHP_EOL;
    foreach ($result['items'] as $item) {
        echo '- #' . (int)($item['id'] ?? 0) . ' ' . ($item['type'] ?? '') . ' ' . ($item['slug'] ?? '') . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] AI content cron failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
