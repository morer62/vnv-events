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

$pages = array_values($pagesByRoute);
$categories = build_location_categories($pages);

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'pages' => $pages,
    'categories' => $categories,
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
        $categoryImageSelect = $hasCmsCategoryImage ? 'cc.featured_image_url' : 'NULL';
        $siteKey = SiteContext::siteKey();

        $db->query("
            SELECT
                c.title,
                c.slug,
                c.excerpt,
                c.meta_description,
                c.target_location,
                c.featured_image_url,
                r.route,
                cc.name AS category_name,
                cc.slug AS category_slug,
                {$categoryImageSelect} AS category_image_url
            FROM cms_contents c
            INNER JOIN cms_routes r ON r.id_content = c.id AND r.is_main = 1
            LEFT JOIN cms_categories cc ON cc.id = c.id_cms_category
            WHERE LOWER(COALESCE(NULLIF(c.content_type, ''), NULLIF(r.route_type, ''), NULLIF(c.type, ''), 'page')) IN ('location', 'locations', 'location_page', 'location-page')
              AND c.status = 'PUBLISHED'
              AND COALESCE(c.approval_status, 'APPROVED') IN ('APPROVED', 'PUBLISHED')
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

function build_location_categories(array $pages): array
{
    $categories = [];

    foreach ($pages as $page) {
        $slug = (string)($page['category_slug'] ?? 'location');
        if (!isset($categories[$slug])) {
            $categories[$slug] = [
                'name' => (string)($page['category'] ?? 'Location'),
                'slug' => $slug,
                'image_url' => (string)($page['category_image_url'] ?? ''),
                'count' => 0,
            ];
        }

        $categories[$slug]['count']++;

        if ($categories[$slug]['image_url'] === '' && !empty($page['category_image_url'])) {
            $categories[$slug]['image_url'] = (string)$page['category_image_url'];
        }
    }

    uasort($categories, static fn ($a, $b) => strcmp($a['name'], $b['name']));

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
