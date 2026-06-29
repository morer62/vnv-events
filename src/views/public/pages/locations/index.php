<?php

use App\Repositories\LocationPagesRepository;
use App\Repositories\Connection;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$repo = new LocationPagesRepository();
$pagesByRoute = [];

foreach ($repo->getAllPublished() as $page) {
    $normalized = normalize_location_legacy_page($page);
    $pagesByRoute[$normalized['public_path']] = $normalized;
}

foreach (get_growth_hub_location_pages() as $page) {
    $pagesByRoute[$page['public_path']] = $page;
}

$allPages = array_values($pagesByRoute);
$categories = get_location_categories($allPages);
$perPage = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalItems = count($allPages);
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;
$pages = array_slice($allPages, $offset, $perPage);

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'pages' => $pages,
    'categories' => $categories,
    'pagination' => [
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'total_items' => $totalItems,
        'per_page' => $perPage,
        'base_path' => 'locations',
    ],
    'placeholder_image' => 'assets/images/cms-image-needed.svg',
    'show_whatsapp' => true
]);

function normalize_location_legacy_page(object $page): array
{
    $category = trim((string)($page->category ?? ''));
    if ($category === '') {
        $category = 'Location';
    }

    return [
        'title' => (string)($page->title ?? ''),
        'slug' => (string)($page->slug ?? ''),
        'public_path' => '/locations/' . trim((string)($page->slug ?? ''), '/') . '/',
        'category' => $category,
        'category_slug' => slugify_location_value($category),
        'category_image_url' => '',
        'hero_image' => (string)($page->hero_image ?? ''),
        'excerpt' => (string)($page->excerpt ?? ''),
        'city' => (string)($page->city ?? ''),
        'county' => (string)($page->county ?? ''),
        'state' => (string)($page->state ?? ''),
        'source' => 'legacy_location_page',
    ];
}

function get_growth_hub_location_pages(): array
{
    try {
        $db = new Connection();
        $hasCmsCategoryImage = table_has_column($db, 'cms_categories', 'featured_image_url');
        $hasTargetLocation = table_has_column($db, 'cms_contents', 'target_location');
        $hasRouteType = table_has_column($db, 'cms_routes', 'route_type');
        $categoryImageSelect = $hasCmsCategoryImage ? 'cc.featured_image_url' : 'NULL';
        $targetLocationSelect = $hasTargetLocation ? 'c.target_location' : 'NULL';
        $typeExpression = $hasRouteType
            ? "LOWER(COALESCE(NULLIF(c.content_type, ''), NULLIF(r.route_type, ''), NULLIF(c.type, ''), 'page'))"
            : "LOWER(COALESCE(NULLIF(c.content_type, ''), NULLIF(c.type, ''), 'page'))";
        $siteKey = SiteContext::siteKey();

        $db->query("
            SELECT
                c.title,
                c.slug,
                c.excerpt,
                c.meta_description,
                {$targetLocationSelect} AS target_location,
                c.featured_image_url,
                c.id_cms_category,
                r.route,
                cc.name AS category_name,
                cc.slug AS category_slug,
                {$categoryImageSelect} AS category_image_url
            FROM cms_contents c
            INNER JOIN cms_routes r ON r.id_content = c.id AND r.is_main = 1
            LEFT JOIN cms_categories cc ON cc.id = c.id_cms_category
            WHERE {$typeExpression} IN ('location', 'locations', 'location_page', 'location-page')
              AND c.status = 'PUBLISHED'
              AND COALESCE(r.status, 'ACTIVE') = 'ACTIVE'
              AND COALESCE(c.language, 'en') = 'en'
              AND c.site_key IN (:site_key, 'shared', 'global', 'all_sites')
            ORDER BY c.published_at DESC, c.updated_at DESC, c.id DESC
        ");
        $db->bind(':site_key', $siteKey);

        $items = [];
        foreach ($db->fetchAll() ?: [] as $row) {
            $category = trim((string)($row->category_name ?? ''));
            if ($category === '') {
                $category = 'Location';
            }

            $items[] = [
                'title' => (string)($row->title ?? ''),
                'slug' => (string)($row->slug ?? ''),
                'public_path' => (string)($row->route ?? ('/locations/' . trim((string)($row->slug ?? ''), '/') . '/')),
                'category' => $category,
                'category_slug' => (string)($row->category_slug ?? '') ?: slugify_location_value($category),
                'category_id' => (int)($row->id_cms_category ?? 0),
                'category_image_url' => (string)($row->category_image_url ?? ''),
                'hero_image' => (string)($row->featured_image_url ?? ''),
                'excerpt' => (string)(($row->excerpt ?? '') ?: ($row->meta_description ?? '')),
                'city' => (string)($row->target_location ?? ''),
                'county' => '',
                'state' => '',
                'source' => 'growth_hub_cms_content',
            ];
        }

        return $items;
    } catch (\Throwable $e) {
        error_log('Growth Hub location list failed: ' . $e->getMessage());
        return [];
    }
}

function get_location_categories(array $pages): array
{
    $countsById = [];
    $countsBySlug = [];
    $imagesBySlug = [];

    foreach ($pages as $page) {
        $id = (int)($page['category_id'] ?? 0);
        $slug = (string)($page['category_slug'] ?? '');

        if ($id > 0) {
            $countsById[$id] = ($countsById[$id] ?? 0) + 1;
        }

        if ($slug !== '') {
            $countsBySlug[$slug] = ($countsBySlug[$slug] ?? 0) + 1;
            if (empty($imagesBySlug[$slug]) && !empty($page['category_image_url'])) {
                $imagesBySlug[$slug] = (string)$page['category_image_url'];
            }
        }
    }

    $categories = [];
    try {
        $db = new Connection();
        $siteKey = strtolower(trim(SiteContext::siteKey()));
        $hasCategoryImage = table_has_column($db, 'cms_categories', 'featured_image_url');
        $categoryImageSelect = $hasCategoryImage ? 'featured_image_url' : "NULL AS featured_image_url";
        $where = ["COALESCE(is_active, 1) = 1"];

        if (table_has_column($db, 'cms_categories', 'applies_to_locations')) {
            $where[] = "COALESCE(applies_to_locations, 1) = 1";
        }

        if ($siteKey !== '' && table_has_column($db, 'cms_categories', 'site_key')) {
            $where[] = "(site_key IS NULL OR site_key = '' OR LOWER(site_key) IN (:site_key, 'shared', 'global', 'all_sites'))";
        }

        $db->query("
            SELECT id, name, slug, description, {$categoryImageSelect}
            FROM cms_categories
            WHERE " . implode(' AND ', $where) . "
            ORDER BY name ASC
        ");
        if ($siteKey !== '' && table_has_column($db, 'cms_categories', 'site_key')) {
            $db->bind(':site_key', $siteKey);
        }

        foreach ($db->fetchAll() ?: [] as $category) {
            $slug = (string)($category->slug ?? '');
            if ($slug === '') {
                continue;
            }

            $categories[$slug] = [
                'id' => (int)($category->id ?? 0),
                'name' => (string)($category->name ?? 'Location'),
                'slug' => $slug,
                'description' => (string)($category->description ?? ''),
                'image_url' => (string)(($category->featured_image_url ?? '') ?: ($imagesBySlug[$slug] ?? '')),
                'count' => (int)($countsById[(int)($category->id ?? 0)] ?? $countsBySlug[$slug] ?? 0),
            ];
        }
    } catch (\Throwable $e) {
        error_log('Location categories failed: ' . $e->getMessage());
    }

    foreach ($pages as $page) {
        $slug = (string)($page['category_slug'] ?? '');
        if ($slug === '' || isset($categories[$slug])) {
            continue;
        }

        $categories[$slug] = [
            'id' => (int)($page['category_id'] ?? 0),
            'name' => (string)($page['category'] ?? 'Location'),
            'slug' => $slug,
            'description' => '',
            'image_url' => (string)($page['category_image_url'] ?? ''),
            'count' => (int)($countsBySlug[$slug] ?? 1),
        ];
    }

    return array_values($categories);
}

function table_has_column(Connection $db, string $table, string $column): bool
{
    try {
        $db->query("SHOW COLUMNS FROM `{$table}` LIKE :column");
        $db->bind(':column', $column);
        return (bool)$db->fetchOne();
    } catch (\Throwable $e) {
        return false;
    }
}

function slugify_location_value(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'location';
}
