<?php

use App\Services\OphyraGrowthHubClient;
use App\Services\PublicSeoService;
use App\Utils\TemplateResponse;

$client = new OphyraGrowthHubClient();
$route = (string)($GLOBALS['growth_hub_route'] ?? '');
$listType = $GLOBALS['growth_hub_list_type'] ?? null;
$expectedType = $GLOBALS['growth_hub_expected_type'] ?? null;

if (!$client->isConfigured()) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

if ($listType) {
    $items = $client->contentList((string)$listType, 80);
    echo TemplateResponse::render(__DIR__ . '/index.twig', [
        'mode' => 'list',
        'list_type' => $listType,
        'items' => $items,
        'site_key' => $client->siteKey(),
        'internal_links' => PublicSeoService::defaultInternalLinks(),
        'show_whatsapp' => true,
    ]);
    exit;
}

$content = $client->contentByRoute($route);

if (!$content || ($expectedType && ($content['content_type'] ?? '') !== $expectedType)) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$content = normalize_growth_hub_content($content);

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'mode' => 'detail',
    'content' => $content,
    'site_key' => $client->siteKey(),
    'internal_links' => PublicSeoService::defaultInternalLinks(),
    'show_whatsapp' => true,
]);
exit;

function normalize_growth_hub_content(array $content): array
{
    foreach (['schema_json', 'metadata'] as $key) {
        if (!empty($content[$key]) && is_string($content[$key])) {
            $decoded = json_decode($content[$key], true);
            if (is_array($decoded)) {
                $content[$key] = $decoded;
            }
        }
    }

    if (!empty($content['blocks']) && is_array($content['blocks'])) {
        foreach ($content['blocks'] as &$block) {
            if (!empty($block['data_json']) && is_string($block['data_json'])) {
                $decoded = json_decode($block['data_json'], true);
                if (is_array($decoded)) {
                    $block['data_json'] = $decoded;
                }
            }
        }
        unset($block);
    }

    return $content;
}
