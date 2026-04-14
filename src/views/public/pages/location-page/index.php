<?php

use App\Repositories\LocationPagesRepository;
use App\Responses\TemplateResponse;

require_once __DIR__ . '/../../../../vendor/autoload.php';

$repo = new LocationPagesRepository();

$url = $_GET['url'] ?? '';
$urlParts = array_values(array_filter(explode('/', trim($url, '/'))));
$slug = $urlParts[0] ?? null;

if (!$slug) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$page = $repo->getPublishedBySlug($slug);

if (!$page) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$page->gallery = !empty($page->gallery_json) ? json_decode($page->gallery_json, true) : [];
$page->faqs = !empty($page->faq_json) ? json_decode($page->faq_json, true) : [];
$page->dynamic_blocks = !empty($page->dynamic_blocks_json) ? json_decode($page->dynamic_blocks_json, true) : [];
$page->schema = !empty($page->schema_json) ? json_decode($page->schema_json, true) : null;

$template = new TemplateResponse('public/pages/location-page/index.twig', [
    'page' => $page,
    'show_whatsapp' => true,
]);

$template->send();