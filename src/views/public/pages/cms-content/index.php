<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Services\PublicSeoService;
use App\Utils\TemplateResponse;

$db = new Connection();

$contentsRepository = new CmsContentsRepository();
$contentsRepository->db = $db;

$routesRepository = new CmsRoutesRepository();
$routesRepository->db = $db;

// Normalizar URL
$url = trim($_GET['url'] ?? '', '/');
$normalizedRoute = $routesRepository->normalizeRoute($url);

// Buscar ruta
$route = $routesRepository->getByRoute($normalizedRoute, 'en');

if (!$route) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

// Obtener contenido
$content = $contentsRepository->getOneWithTemplate((int)$route->id_content);

if (!$content) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$publicType = normalize_public_cms_content_type((string)(
    ($content->content_type ?? '')
    ?: ($route->route_type ?? '')
    ?: ($content->type ?? '')
    ?: 'page'
));

if (
    !in_array($publicType, ['page', 'location'], true) ||
    ($content->status ?? '') !== 'PUBLISHED' ||
    ($content->language ?? 'en') !== 'en'
) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

// Parsear JSON
$contentJson = [];
if (!empty($content->content_json)) {
    $decoded = json_decode($content->content_json, true);
    if (is_array($decoded)) {
        $contentJson = $decoded;
    }
}

$seo = PublicSeoService::contentSeo($content, $route, $publicType === 'location' ? 'location' : 'page');
$schemaJson = PublicSeoService::pageSchema($content, $route, $contentJson);

if (cms_body_is_full_twig_template((string)($content->body_html ?? ''))) {
    echo TemplateResponse::renderString((string)$content->body_html, [
        "page" => $content,
        "route" => $route,
        "content_json" => $contentJson,
        "internal_links" => PublicSeoService::defaultInternalLinks(),
        "seo" => $seo,
        "schemaJson" => $schemaJson,
        "show_whatsapp" => true,
    ]);
    exit;
}

// Render
echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "page" => $content,
    "route" => $route,
    "content_json" => $contentJson,
    "internal_links" => PublicSeoService::defaultInternalLinks(),
    "seo" => $seo,
    "schemaJson" => $schemaJson,
    "show_whatsapp" => true,
]);
exit;

function normalize_public_cms_content_type(string $contentType): string
{
    $contentType = strtolower(trim($contentType));

    if (in_array($contentType, ['location', 'locations', 'location_page', 'location-page'], true)) {
        return 'location';
    }

    if (in_array($contentType, ['blog', 'post', 'blog_post', 'blog-post'], true)) {
        return 'blog';
    }

    return 'page';
}

function cms_body_is_full_twig_template(string $bodyHtml): bool
{
    $bodyHtml = ltrim($bodyHtml);

    return str_contains($bodyHtml, '{% extends ')
        || str_contains($bodyHtml, '{% block body %}')
        || str_contains($bodyHtml, '{% block styles %}');
}
