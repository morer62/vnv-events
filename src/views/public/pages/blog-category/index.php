<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\TemplateResponse;

$db = new Connection();

$blogCategoriesRepository = new BlogCategoriesRepository();
$blogCategoriesRepository->db = $db;

// URL esperada: /category/blog/{slug}
$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];

$slug = $parts[2] ?? null;

if (!$slug) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

// Buscar categoría
$category = $blogCategoriesRepository->getBySlug($slug);

if (!$category || ($category->status ?? null) !== 'ACTIVE') {
    http_response_code(404);
    echo "Category not found";
    exit;
}

// Buscar posts publicados de esa categoría con su ruta principal
$db->query("
    SELECT 
        c.*,
        r.route AS main_route
    FROM cms_contents c
    LEFT JOIN cms_routes r 
        ON r.id_content = c.id
       AND r.is_main = 1
       AND r.language = c.language
       AND r.status = 'ACTIVE'
    WHERE c.type = 'post'
      AND c.status = 'PUBLISHED'
      AND c.language = 'en'
      AND c.id_blog_category = :cat
    ORDER BY c.published_at DESC, c.id DESC
");

$db->bind(':cat', (int)$category->id);

$posts = $db->fetchAll() ?: [];

return TemplateResponse::render(__DIR__ . "/index.twig", [
    "category" => $category,
    "posts"    => $posts,
]);