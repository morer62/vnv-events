<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\LocationUtils;
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

// Buscar posts publicados de esa categoría con su ruta principal.
// Si el esquema CMS no está migrado aún (tabla cms_contents/cms_routes), evitamos romper la página.
$posts = [];
try {
    $db->query("SHOW TABLES LIKE :table_name");
    $db->bind(':table_name', 'cms_contents');
    $hasCmsContents = (bool)$db->fetchOne();

    if (!$hasCmsContents) {
        throw new \RuntimeException("Missing table cms_contents in current database.");
    }

    $db->query("SHOW COLUMNS FROM cms_contents LIKE :column_name");
    $db->bind(':column_name', 'status');
    $hasContentsStatus = (bool)$db->fetchOne();

    $db->query("SHOW COLUMNS FROM cms_routes LIKE :column_name");
    $db->bind(':column_name', 'status');
    $hasRoutesStatus = (bool)$db->fetchOne();

    $contentsStatusFilter = $hasContentsStatus ? " AND c.status = 'PUBLISHED'" : "";
    $routesStatusFilter = $hasRoutesStatus ? " AND r.status = 'ACTIVE'" : "";

    $db->query("
        SELECT 
            c.*,
            r.route AS main_route
        FROM cms_contents c
        LEFT JOIN cms_routes r 
            ON r.id_content = c.id
           AND r.is_main = 1
           AND r.language = c.language
           {$routesStatusFilter}
        WHERE c.type = 'post'
          AND c.language = 'en'
          AND c.id_blog_category = :cat
          {$contentsStatusFilter}
        ORDER BY c.published_at DESC, c.id DESC
    ");

    $db->bind(':cat', (int)$category->id);
    $posts = $db->fetchAll() ?: [];
} catch (\Throwable $e) {
    $logFile = LocationUtils::getRootLocation() . '/.logs/app_error_' . date('Y-m-d') . '.log';
    error_log(
        '[PUBLIC_BLOG_CATEGORY] Failed fetching posts for category slug "' . $slug . '": ' . $e->getMessage() . PHP_EOL,
        3,
        $logFile
    );
}

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "category" => $category,
    "posts"    => $posts,
]);
exit;