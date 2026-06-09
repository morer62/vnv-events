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

// Render
echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "page" => $content,
    "route" => $route,
    "content_json" => $contentJson,
    "internal_links" => PublicSeoService::defaultInternalLinks(),
    "seo" => PublicSeoService::contentSeo($content, $route, 'page'),
    "schemaJson" => PublicSeoService::pageSchema($content, $route, $contentJson),
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
