<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$db = new Connection();
$siteKey = strtolower(trim(SiteContext::siteKey()));

$rawCategoryType = null;
if (isset($GLOBALS['public_category_type']) && is_string($GLOBALS['public_category_type'])) {
    $rawCategoryType = $GLOBALS['public_category_type'];
}

$rawCategorySlug = null;
if (isset($GLOBALS['public_category_slug']) && is_string($GLOBALS['public_category_slug'])) {
    $rawCategorySlug = $GLOBALS['public_category_slug'];
}

$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];

$categoryType = normalize_public_category_type((string)($rawCategoryType ?? $parts[1] ?? ''));
$slug = $rawCategorySlug ?? ($parts[2] ?? null);

if (!$categoryType && !empty($parts[1])) {
    $categoryType = normalize_public_category_type($parts[1]);
}

if (!$categoryType || !$slug) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

$categoryId = null;
$category = null;
$typeWhere = "1 = 0";
$categoryRepository = null;
$categoryTitle = '';
$categoryLabel = normalize_public_category_type_label($categoryType);
$perPage = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalItems = 0;
$totalPages = 1;

$categoryRepository = new CmsCategoriesRepository();
$categoryRepository->db = $db;

$category = $categoryRepository->getActiveBySlugForContentType((string)$slug, $categoryType);
if ((!$category || !public_category_matches_site($category, $siteKey)) && $categoryType === 'blog') {
    $legacyCategoryRepository = new BlogCategoriesRepository();
    $legacyCategoryRepository->db = $db;
    $legacyCategory = $legacyCategoryRepository->getBySlug((string)$slug);

    if ($legacyCategory && ($legacyCategory->status ?? null) === 'ACTIVE' && public_category_matches_site($legacyCategory, $siteKey)) {
        $category = $legacyCategory;
        $categoryColumn = "c.id_blog_category";
    }
}

if (!$category || !public_category_matches_site($category, $siteKey)) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

$categoryId = (int)($category->id ?? 0);
$categoryColumn = $categoryColumn ?? "c.id_cms_category";
$legacyCategoryId = 0;
if ($categoryType === 'blog' && $categoryColumn === "c.id_cms_category") {
    $legacyCategoryRepository = new BlogCategoriesRepository();
    $legacyCategoryRepository->db = $db;
    $legacyCategory = $legacyCategoryRepository->getBySlug((string)$slug);
    if ($legacyCategory && ($legacyCategory->status ?? null) === 'ACTIVE' && public_category_matches_site($legacyCategory, $siteKey)) {
        $legacyCategoryId = (int)($legacyCategory->id ?? 0);
    }
}
$categoryFilterSql = "{$categoryColumn} = :category_id";
if ($legacyCategoryId > 0) {
    $categoryFilterSql = "(c.id_cms_category = :category_id OR c.id_blog_category = :legacy_category_id)";
}
$typeWhere = match ($categoryType) {
    'blog' => "(c.type = 'post' OR c.content_type = 'blog' OR c.content_type = 'blog_post')",
    'location' => "c.content_type IN ('location','location_page','location-page')",
    default => "(c.type = 'page' OR c.content_type = 'page' OR c.content_type = '' OR c.content_type IS NULL)",
};

if (!$categoryId) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

$items = [];
try {
    $db->query("SHOW TABLES LIKE :table_name");
    $db->bind(':table_name', 'cms_contents');
    if (!$db->fetchOne()) {
        throw new \RuntimeException("Missing table cms_contents in current database.");
    }

    $db->query("SHOW TABLES LIKE :table_name");
    $db->bind(':table_name', 'cms_routes');
    if (!$db->fetchOne()) {
        throw new \RuntimeException("Missing table cms_routes in current database.");
    }

    $db->query("SHOW COLUMNS FROM cms_contents LIKE :column_name");
    $db->bind(':column_name', 'status');
    $hasStatus = (bool)$db->fetchOne();

    $db->query("SHOW COLUMNS FROM cms_routes LIKE :column_name");
    $db->bind(':column_name', 'status');
    $hasRouteStatus = (bool)$db->fetchOne();

    $db->query("SHOW COLUMNS FROM cms_contents LIKE :column_name");
    $db->bind(':column_name', 'site_key');
    $hasContentSiteKey = (bool)$db->fetchOne();

    $db->query("SHOW COLUMNS FROM cms_routes LIKE :column_name");
    $db->bind(':column_name', 'site_key');
    $hasRouteSiteKey = (bool)$db->fetchOne();

    $contentStatusFilter = $hasStatus ? " AND c.status = 'PUBLISHED'" : "";
    $routeStatusFilter = $hasRouteStatus ? " AND r.status = 'ACTIVE'" : "";
    $contentSiteKeyFilter = $hasContentSiteKey ? " AND c.site_key IN (:content_site_key, 'shared', 'global', 'all_sites')" : "";
    $routeSiteKeyFilter = $hasRouteSiteKey ? " AND r.site_key IN (:route_site_key, 'shared', 'global', 'all_sites')" : "";

    $countSql = "
        SELECT COUNT(*) AS total
        FROM cms_contents c
        LEFT JOIN cms_routes r
            ON r.id_content = c.id
           AND r.is_main = 1
           AND r.language = c.language
           {$routeStatusFilter}
           {$routeSiteKeyFilter}
        WHERE {$categoryFilterSql}
          AND c.language = 'en'
          {$contentStatusFilter}
          {$contentSiteKeyFilter}
          AND {$typeWhere}
    ";

    $db->query($countSql);
    $db->bind(':category_id', $categoryId);
    if ($legacyCategoryId > 0) {
        $db->bind(':legacy_category_id', $legacyCategoryId);
    }
    if ($hasContentSiteKey) {
        $db->bind(':content_site_key', $siteKey);
    }
    if ($hasRouteSiteKey) {
        $db->bind(':route_site_key', $siteKey);
    }
    $countRow = $db->fetchOne();
    $totalItems = $countRow ? (int)($countRow->total ?? 0) : 0;
    $totalPages = max(1, (int)ceil($totalItems / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $db->query("
        SELECT
            c.*,
            r.route AS main_route
        FROM cms_contents c
        LEFT JOIN cms_routes r
            ON r.id_content = c.id
           AND r.is_main = 1
           AND r.language = c.language
           {$routeStatusFilter}
           {$routeSiteKeyFilter}
        WHERE {$categoryFilterSql}
          AND c.language = 'en'
          {$contentStatusFilter}
          {$contentSiteKeyFilter}
          AND {$typeWhere}
        ORDER BY c.published_at DESC, c.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");

    $db->bind(':category_id', $categoryId);
    if ($legacyCategoryId > 0) {
        $db->bind(':legacy_category_id', $legacyCategoryId);
    }
    if ($hasContentSiteKey) {
        $db->bind(':content_site_key', $siteKey);
    }
    if ($hasRouteSiteKey) {
        $db->bind(':route_site_key', $siteKey);
    }
    $items = $db->fetchAll() ?: [];
} catch (\Throwable $e) {
    $logFile = LocationUtils::getRootLocation() . '/.logs/app_error_' . date('Y-m-d') . '.log';
    error_log(
        '[PUBLIC_CATEGORY] Failed fetching items for type "' . $categoryType . '" and slug "' . $slug . '": ' . $e->getMessage() . PHP_EOL,
        3,
        $logFile
    );
}

$categoryTitle = ($category->name ?? 'Category');

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "category" => $category,
    "category_type" => $categoryType,
    "category_type_label" => $categoryLabel,
    "category_title" => $categoryTitle,
    "items" => $items,
    "pagination" => [
        "current_page" => $currentPage,
        "total_pages" => $totalPages,
        "total_items" => $totalItems,
        "per_page" => $perPage,
        "base_path" => public_category_path($categoryType, (string)$slug),
    ],
    "placeholder_image" => "assets/images/cms-image-needed.svg",
    "show_whatsapp" => true,
]);
exit;

function normalize_public_category_type(string $type): ?string
{
    $type = strtolower(trim($type));
    if (in_array($type, ['blog', 'post', 'blog_post', 'blog-post'], true)) {
        return 'blog';
    }

    if (in_array($type, ['page', 'pages'], true)) {
        return 'page';
    }

    if (in_array($type, ['location', 'locations', 'location_page', 'location-page'], true)) {
        return 'location';
    }

    return null;
}

function normalize_public_category_type_label(string $type): string
{
    if ($type === 'location') {
        return 'Locations';
    }

    if ($type === 'page') {
        return 'Page';
    }

    return 'Blog';
}

function public_category_path(string $type, string $slug): string
{
    $prefix = match ($type) {
        'blog' => 'blog',
        'location' => 'locations',
        default => 'pages',
    };

    return '/' . $prefix . '/' . trim($slug, '/') . '/';
}

function public_category_matches_site(object $category, string $siteKey): bool
{
    if (!isset($category->site_key) || trim((string)$category->site_key) === '') {
        return true;
    }

    return in_array(strtolower(trim((string)$category->site_key)), [
        $siteKey,
        'shared',
        'global',
        'all_sites',
    ], true);
}
