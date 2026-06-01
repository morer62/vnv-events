<?php

use App\Repositories\BlogCategoriesRepository;
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

if (
    !$post ||
    ($post->type ?? '') !== 'post' ||
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
if (!empty($post->id_blog_category)) {
    $category = $blogCategoriesRepository->getOne([
        'id' => (int)$post->id_blog_category
    ]);

    if ($category && $category->status !== 'ACTIVE') {
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
