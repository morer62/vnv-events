<?php

use App\Services\OphyraGrowthHubClient;
use App\Services\PublicSeoService;
use App\Repositories\CmsCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\TemplateResponse;

$client = new OphyraGrowthHubClient();
$route = (string)($GLOBALS['growth_hub_route'] ?? '');
$listType = $GLOBALS['growth_hub_list_type'] ?? null;
$expectedType = $GLOBALS['growth_hub_expected_type'] ?? null;

if (!$client->isConfigured()) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

if ($listType) {
    $categories = growth_hub_list_categories((string)$listType);
    $categoryLookup = growth_hub_category_lookup($categories);
    $items = growth_hub_content_list($client, (string)$listType, 80);
    $items = growth_hub_normalize_list_items($items, $categoryLookup, (string)$listType);

    echo TemplateResponse::render(__DIR__ . '/index.twig', [
        'mode' => 'list',
        'list_type' => $listType,
        'items' => $items,
        'categories' => $categories,
        'placeholder_image' => 'assets/images/cms-image-needed.svg',
        'site_key' => $client->siteKey(),
        'internal_links' => PublicSeoService::defaultInternalLinks(),
        'show_whatsapp' => true,
    ]);
    exit;
}

$content = $client->contentByRoute($route);

if (!$content || ($expectedType && normalize_growth_hub_content_type((string)($content['content_type'] ?? 'page')) !== normalize_growth_hub_content_type((string)$expectedType))) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

$content = normalize_growth_hub_content($content);

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'mode' => 'detail',
    'content' => $content,
    'site_key' => $client->siteKey(),
    'internal_links' => PublicSeoService::defaultInternalLinks(),
    'show_whatsapp' => true,
]);
exit;

function normalize_growth_hub_content(array $content): array
{
    $content['content_type'] = normalize_growth_hub_content_type((string)($content['content_type'] ?? 'page'));

    foreach (['schema_json', 'metadata'] as $key) {
        if (!empty($content[$key]) && is_string($content[$key])) {
            $decoded = json_decode($content[$key], true);
            if (is_array($decoded)) {
                $content[$key] = $decoded;
            }
        }
    }

    if (!empty($content['blocks']) && is_array($content['blocks'])) {
        foreach ($content['blocks'] as &$block) {
            if (!empty($block['data_json']) && is_string($block['data_json'])) {
                $decoded = json_decode($block['data_json'], true);
                if (is_array($decoded)) {
                    $block['data_json'] = $decoded;
                }
            }
        }
        unset($block);
    }

    return $content;
}

function normalize_growth_hub_content_type(string $contentType): string
{
    $contentType = strtolower(trim($contentType));

    if ($contentType === 'location') {
        return 'location';
    }

    if (in_array($contentType, ['location_page', 'location-page', 'locations'], true)) {
        return 'location';
    }

    if (in_array($contentType, ['blog', 'post', 'blog_post', 'guide', 'faq_page', 'comparison', 'case_study', 'blog-post'], true)) {
        return 'blog';
    }

    return 'page';
}

function growth_hub_content_list(OphyraGrowthHubClient $client, string $listType, int $limit = 80): array
{
    $itemsByRoute = [];

    foreach (growth_hub_local_content_list($listType, $client->siteKey(), $limit) as $item) {
        $route = (string)($item['route'] ?? '');
        if ($route !== '') {
            $itemsByRoute[$route] = $item;
        }
    }

    foreach ($client->contentList($listType, $limit) as $item) {
        $route = (string)($item['route'] ?? '');
        if ($route !== '') {
            $itemsByRoute[$route] = $item;
        }
    }

    return array_slice(array_values($itemsByRoute), 0, $limit);
}

function growth_hub_local_content_list(string $listType, string $siteKey, int $limit = 80): array
{
    try {
        $db = new Connection();
        $hasCmsCategoryImage = growth_hub_table_has_column($db, 'cms_categories', 'featured_image_url');
        $hasBlogCategoryImage = growth_hub_table_has_column($db, 'blog_categories', 'featured_image_url');
        $hasRouteType = growth_hub_table_has_column($db, 'cms_routes', 'route_type');

        $typeExpression = $hasRouteType
            ? "LOWER(COALESCE(NULLIF(c.content_type, ''), NULLIF(r.route_type, ''), NULLIF(c.type, ''), 'page'))"
            : "LOWER(COALESCE(NULLIF(c.content_type, ''), NULLIF(c.type, ''), 'page'))";
        $typeValues = $listType === 'blog'
            ? "'blog', 'post', 'blog_post', 'blog-post', 'guide', 'faq_page', 'comparison', 'case_study'"
            : "'location', 'locations', 'location_page', 'location-page'";

        $cmsCategoryImageSelect = $hasCmsCategoryImage ? "cc.featured_image_url" : "NULL";
        $blogCategoryImageSelect = $hasBlogCategoryImage ? "bc.featured_image_url" : "NULL";
        $routeTypeSelect = $hasRouteType ? "r.route_type" : "NULL";
        $db->query("
            SELECT
                c.id,
                c.title,
                c.slug,
                c.excerpt,
                c.featured_image_url,
                c.meta_description,
                c.published_at,
                c.updated_at,
                c.site_key,
                c.content_type,
                c.type,
                c.id_cms_category,
                c.id_blog_category,
                r.route,
                {$routeTypeSelect} AS route_type,
                COALESCE(bc.name, cc.name) AS category_name,
                COALESCE(bc.slug, cc.slug) AS category_slug,
                COALESCE({$blogCategoryImageSelect}, {$cmsCategoryImageSelect}) AS category_image_url
            FROM cms_contents c
            INNER JOIN cms_routes r ON r.id_content = c.id AND r.is_main = 1
            LEFT JOIN blog_categories bc ON bc.id = c.id_blog_category
            LEFT JOIN cms_categories cc ON cc.id = c.id_cms_category
            WHERE {$typeExpression} IN ({$typeValues})
              AND c.status = 'PUBLISHED'
              AND COALESCE(r.status, 'ACTIVE') = 'ACTIVE'
              AND COALESCE(c.language, 'en') = 'en'
              AND c.site_key IN (:site_key, 'shared', 'global', 'all_sites')
            ORDER BY c.published_at DESC, c.updated_at DESC, c.id DESC
            LIMIT " . max(1, $limit)
        );
        $db->bind(':site_key', strtolower(trim($siteKey)));

        return array_map(static fn ($row) => (array)$row, $db->fetchAll() ?: []);
    } catch (\Throwable $e) {
        error_log('Growth Hub local list fallback failed: ' . $e->getMessage());
        return [];
    }
}

function growth_hub_table_has_column(Connection $db, string $table, string $column): bool
{
    try {
        $db->query("SHOW COLUMNS FROM `{$table}` LIKE :column");
        $db->bind(':column', $column);
        return (bool)$db->fetchOne();
    } catch (\Throwable $e) {
        return false;
    }
}

function growth_hub_list_categories(string $listType): array
{
    try {
        $db = new Connection();

        $repo = new CmsCategoriesRepository();
        $repo->db = $db;

        return array_map(static function ($category) {
            return [
                'id' => (int)($category->id ?? 0),
                'name' => (string)($category->name ?? 'Category'),
                'slug' => (string)($category->slug ?? ''),
                'description' => (string)($category->description ?? ''),
                'image_url' => (string)($category->featured_image_url ?? ''),
            ];
        }, $repo->getActiveForContentType($listType) ?: []);
    } catch (\Throwable $e) {
        error_log('Growth Hub category list failed: ' . $e->getMessage());
        return [];
    }
}

function growth_hub_category_lookup(array $categories): array
{
    $lookup = [
        'by_id' => [],
        'by_slug' => [],
    ];

    foreach ($categories as $category) {
        $id = (int)($category['id'] ?? 0);
        $slug = (string)($category['slug'] ?? '');

        if ($id > 0) {
            $lookup['by_id'][$id] = $category;
        }

        if ($slug !== '') {
            $lookup['by_slug'][$slug] = $category;
        }
    }

    return $lookup;
}

function growth_hub_normalize_list_items(array $items, array $categoryLookup, string $listType): array
{
    return array_map(static function ($item) use ($categoryLookup, $listType) {
        $item = (array)$item;

        $categoryId = (int)($item['id_blog_category'] ?? $item['blog_category_id'] ?? $item['id_cms_category'] ?? $item['cms_category_id'] ?? $item['category_id'] ?? 0);
        $categorySlug = trim((string)($item['category_slug'] ?? $item['blog_category_slug'] ?? $item['cms_category_slug'] ?? ($item['category']['slug'] ?? '')));
        $category = null;

        if ($categoryId > 0 && isset($categoryLookup['by_id'][$categoryId])) {
            $category = $categoryLookup['by_id'][$categoryId];
        } elseif ($categorySlug !== '' && isset($categoryLookup['by_slug'][$categorySlug])) {
            $category = $categoryLookup['by_slug'][$categorySlug];
        }

        $categoryName = trim((string)($item['category_name'] ?? $item['blog_category_name'] ?? $item['cms_category_name'] ?? ($item['category']['name'] ?? '')));
        $categoryImage = trim((string)($item['category_image_url'] ?? ($item['category']['featured_image_url'] ?? '')));

        if ($category) {
            $categoryName = $categoryName !== '' ? $categoryName : (string)$category['name'];
            $categorySlug = $categorySlug !== '' ? $categorySlug : (string)$category['slug'];
            $categoryImage = $categoryImage !== '' ? $categoryImage : (string)$category['image_url'];
        }

        if ($categoryName === '') {
            $categoryName = $listType === 'blog' ? 'Blog' : 'Location';
        }

        if ($categorySlug === '') {
            $categorySlug = growth_hub_slugify($categoryName);
        }

        $media = $item['media'][0] ?? [];
        $seo = $item['seo'] ?? [];
        $imageUrl = growth_hub_first_filled([
            $item['featured_image_url'] ?? '',
            $item['thumbnail_url'] ?? '',
            $item['image_url'] ?? '',
            is_array($media) ? ($media['secure_url'] ?? '') : '',
            is_array($media) ? ($media['url'] ?? '') : '',
            is_array($seo) ? ($seo['og_image'] ?? '') : '',
            $item['og_image'] ?? '',
            $categoryImage,
        ]);

        $excerpt = growth_hub_first_filled([
            $item['excerpt'] ?? '',
            $item['meta_description'] ?? '',
            is_array($seo) ? ($seo['description'] ?? '') : '',
        ]);

        $item['_category_name'] = $categoryName;
        $item['_category_slug'] = $categorySlug;
        $item['_category_image_url'] = $categoryImage;
        $item['_image_url'] = $imageUrl;
        $item['_excerpt'] = $excerpt;
        $item['_search_text'] = strtolower(trim(($item['title'] ?? '') . ' ' . $excerpt . ' ' . $categoryName . ' ' . $categorySlug));

        return $item;
    }, $items);
}

function growth_hub_first_filled(array $values): string
{
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function growth_hub_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'uncategorized';
}
