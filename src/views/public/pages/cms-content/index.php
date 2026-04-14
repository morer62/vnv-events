<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Utils\TemplateResponse;

$db = new Connection();

$contentsRepository = new CmsContentsRepository();
$contentsRepository->db = $db;

$routesRepository = new CmsRoutesRepository();
$routesRepository->db = $db;

$url = $_GET['url'] ?? '';
$normalizedRoute = $routesRepository->normalizeRoute($url);

$route = $routesRepository->getByRoute($normalizedRoute, 'en');

if (!$route) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$content = $contentsRepository->getOneWithTemplate((int)$route->id_content);

if (!$content) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

if (($content->type ?? '') !== 'page') {
    http_response_code(404);
    echo "Page not found";
    exit;
}

if (($content->status ?? '') !== 'PUBLISHED') {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$contentJson = [];
if (!empty($content->content_json)) {
    $decoded = json_decode($content->content_json, true);
    if (is_array($decoded)) {
        $contentJson = $decoded;
    }
}

TemplateResponse::render(__DIR__ . "/index.twig", [
    "page" => $content,
    "route" => $route,
    "content_json" => $contentJson,
    "show_whatsapp" => true,
]);