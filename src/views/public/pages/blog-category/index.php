<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\Connection;
use App\Utils\TemplateResponse;

$db = new Connection();

$blogCategoriesRepository = new BlogCategoriesRepository();
$blogCategoriesRepository->db = $db;

$contentsRepository = new CmsContentsRepository();
$contentsRepository->db = $db;

// URL: /category/blog/{slug}
$url = $_GET['url'] ?? '';
$parts = explode('/', trim($url, '/'));

$slug = $parts[2] ?? null;

if (!$slug) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

// Buscar categoría
$category = $blogCategoriesRepository->getBySlug($slug);

if (!$category || $category->status !== 'ACTIVE') {
    http_response_code(404);
    echo "Category not found";
    exit;
}

// Buscar posts de esa categoría
$db->query("
    SELECT c.*
    FROM cms_contents c
    WHERE c.type = 'post'
    AND c.status = 'PUBLISHED'
    AND c.id_blog_category = :cat
    ORDER BY c.published_at DESC
");

$db->bind(':cat', (int)$category->id);

$posts = $db->fetchAll() ?: [];

TemplateResponse::render(__DIR__ . "/index.twig", [
    "category" => $category,
    "posts" => $posts,
]);