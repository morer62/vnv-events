<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsCategoriesRepository;
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

$cmsCategoriesRepository = new CmsCategoriesRepository();
$cmsCategoriesRepository->db = $db;

$blogCategoriesRepository = new BlogCategoriesRepository();
$blogCategoriesRepository->db = $db;

// Normalizar URL
$url = trim($_GET['url'] ?? '', '/');
$normalizedRoute = $routesRepository->normalizeRoute($url);

// Buscar ruta
$route = $routesRepository->getByRoute($normalizedRoute, 'en');

if (!$route) {
    http_response_code(404);
    echo "Post not found";
    exit;
}

// Obtener contenido
$post = $contentsRepository->getOneWithTemplate((int)$route->id_content);

if (!$post) {
    http_response_code(404);
    echo "Post not found";
    exit;
}

$publicType = normalize_public_blog_content_type((string)(
    ($post->content_type ?? '')
    ?: ($route->route_type ?? '')
    ?: ($post->type ?? '')
    ?: 'post'
));

if (
    $publicType !== 'blog' ||
    ($post->status ?? '') !== 'PUBLISHED' ||
    ($post->language ?? 'en') !== 'en'
) {
    http_response_code(404);
    echo "Post not found";
    exit;
}

// Parsear JSON
$contentJson = [];
if (!empty($post->content_json)) {
    $decoded = json_decode($post->content_json, true);
    if (is_array($decoded)) {
        $contentJson = $decoded;
    }
}

// Categoría
$category = null;
if (!empty($post->id_cms_category)) {
    $category = $cmsCategoriesRepository->getOne([
        'id' => (int)$post->id_cms_category
    ]);

    if ($category && ((int)($category->is_active ?? 0) !== 1 || !$cmsCategoriesRepository->supportsContentType($category, 'blog'))) {
        $category = null;
    }
}

if (!$category && !empty($post->id_blog_category)) {
    $category = $blogCategoriesRepository->getOne([
        'id' => (int)$post->id_blog_category
    ]);

    if ($category && ($category->status ?? null) !== 'ACTIVE') {
        $category = null;
    }
}

// Render
echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "post" => $post,
    "route" => $route,
    "category" => $category,
    "content_json" => $contentJson,
    "internal_links" => PublicSeoService::defaultInternalLinks(),
    "seo" => PublicSeoService::contentSeo($post, $route, 'post'),
    "schemaJson" => PublicSeoService::blogSchema($post, $route, $category),
    "show_whatsapp" => true,
]);
exit;

function normalize_public_blog_content_type(string $contentType): string
{
    $contentType = strtolower(trim($contentType));

    if (in_array($contentType, ['blog', 'post', 'blog_post', 'blog-post', 'guide', 'faq_page', 'comparison', 'case_study'], true)) {
        return 'blog';
    }

    if (in_array($contentType, ['location', 'locations', 'location_page', 'location-page'], true)) {
        return 'location';
    }

    return 'page';
}
