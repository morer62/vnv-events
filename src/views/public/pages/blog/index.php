<?php

use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$siteKey = strtolower(trim(SiteContext::siteKey()));
$categories = [];
$posts = [];
$featuredPosts = [];
$recentPosts = [];

function vnv_blog_query(\App\Repositories\Connection $db, string $sql, array $params = []): array
{
    $db->query($sql);
    foreach ($params as $param => $value) {
        $db->bind($param, $value);
    }

    return array_map(static fn ($row) => (array)$row, $db->fetchAll());
}

function vnv_blog_table_exists(\App\Repositories\Connection $db, string $table): bool
{
    try {
        $result = vnv_blog_query($db, "SHOW TABLES LIKE :table_name", [':table_name' => $table]);
        return !empty($result);
    } catch (Throwable $e) {
        return false;
    }
}

function vnv_blog_column_exists(\App\Repositories\Connection $db, string $table, string $column): bool
{
    try {
        $result = vnv_blog_query($db, "SHOW COLUMNS FROM `{$table}` LIKE :column_name", [':column_name' => $column]);
        return !empty($result);
    } catch (Throwable $e) {
        return false;
    }
}

function vnv_blog_first_existing_column(\App\Repositories\Connection $db, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (vnv_blog_column_exists($db, $table, $column)) {
            return $column;
        }
    }

    return null;
}

try {
    $db = new \App\Repositories\Connection();
    $hasCategories = vnv_blog_table_exists($db, 'cms_categories');
    $hasLegacyCategories = vnv_blog_table_exists($db, 'blog_categories');
    $hasContents = vnv_blog_table_exists($db, 'cms_contents');
    $hasRoutes = vnv_blog_table_exists($db, 'cms_routes');

    if ($hasCategories) {
        $categoryWhere = [];
        $categoryParams = [];

        if (vnv_blog_column_exists($db, 'cms_categories', 'is_active')) {
            $categoryWhere[] = "COALESCE(is_active, 1) = 1";
        }

        if (vnv_blog_column_exists($db, 'cms_categories', 'applies_to_blog')) {
            $categoryWhere[] = "COALESCE(applies_to_blog, 1) = 1";
        }

        if ($siteKey !== '' && vnv_blog_column_exists($db, 'cms_categories', 'site_key')) {
            $categoryWhere[] = "(site_key IS NULL OR site_key = '' OR LOWER(site_key) IN (:category_site_key, 'shared', 'global', 'all_sites'))";
            $categoryParams[':category_site_key'] = $siteKey;
        }

        $categoryOrderColumn = vnv_blog_first_existing_column($db, 'cms_categories', ['sort_order', 'display_order', 'position', 'name']);
        $categoryOrder = $categoryOrderColumn ? " ORDER BY {$categoryOrderColumn} ASC" : " ORDER BY id ASC";
        $categorySql = "SELECT * FROM cms_categories";
        if (!empty($categoryWhere)) {
            $categorySql .= " WHERE " . implode(' AND ', $categoryWhere);
        }
        $categorySql .= $categoryOrder;

        $categories = vnv_blog_query($db, $categorySql, $categoryParams);
    }

    if ($hasContents) {
        $contentWhere = ["c.language = 'en'"];
        $contentParams = [];

        if (vnv_blog_column_exists($db, 'cms_contents', 'status')) {
            $contentWhere[] = "LOWER(COALESCE(c.status, 'published')) IN ('published', 'active', '1')";
        }

        if ($siteKey !== '' && vnv_blog_column_exists($db, 'cms_contents', 'site_key')) {
            $contentWhere[] = "(c.site_key IS NULL OR c.site_key = '' OR LOWER(c.site_key) IN (:content_site_key, 'shared', 'global', 'all_sites'))";
            $contentParams[':content_site_key'] = $siteKey;
        }

        $typeParts = [];
        if (vnv_blog_column_exists($db, 'cms_contents', 'type')) {
            $typeParts[] = "LOWER(COALESCE(c.type, '')) IN ('post', 'blog', 'blog_post')";
        }
        if (vnv_blog_column_exists($db, 'cms_contents', 'content_type')) {
            $typeParts[] = "LOWER(COALESCE(c.content_type, '')) IN ('blog', 'blog_post', 'post')";
        }
        if (!empty($typeParts)) {
            $contentWhere[] = '(' . implode(' OR ', $typeParts) . ')';
        }

        $routeSelect = $hasRoutes ? "r.route AS main_route," : "NULL AS main_route,";
        $routeJoin = '';
        if ($hasRoutes) {
            $routeFilters = [];
            if (vnv_blog_column_exists($db, 'cms_routes', 'status')) {
                $routeFilters[] = "LOWER(COALESCE(r.status, 'active')) IN ('active', 'published', '1')";
            }
            if ($siteKey !== '' && vnv_blog_column_exists($db, 'cms_routes', 'site_key')) {
                $routeFilters[] = "(r.site_key IS NULL OR r.site_key = '' OR LOWER(r.site_key) IN (:route_site_key, 'shared', 'global', 'all_sites'))";
                $contentParams[':route_site_key'] = $siteKey;
            }
            $routeFilterSql = $routeFilters ? " AND " . implode(' AND ', $routeFilters) : "";
            $routeJoin = "LEFT JOIN cms_routes r ON r.id_content = c.id AND r.language = c.language AND (r.is_main = 1 OR r.route = CONCAT('/blog/', c.slug, '/')){$routeFilterSql}";
        }

        $categoryJoinParts = [];
        if ($hasCategories && vnv_blog_column_exists($db, 'cms_contents', 'id_cms_category')) {
            $categoryJoinParts[] = "LEFT JOIN cms_categories cc ON cc.id = c.id_cms_category";
        }
        $legacyCategoryIdColumn = null;
        if ($hasLegacyCategories) {
            $legacyCategoryIdColumn = vnv_blog_first_existing_column($db, 'blog_categories', ['id_blog_category', 'id']);
        }
        if ($hasLegacyCategories && $legacyCategoryIdColumn && vnv_blog_column_exists($db, 'cms_contents', 'id_blog_category')) {
            $categoryJoinParts[] = "LEFT JOIN blog_categories bc ON bc.{$legacyCategoryIdColumn} = c.id_blog_category";
        }
        $categoryJoin = implode("\n            ", $categoryJoinParts);
        $hasLegacyJoin = $hasLegacyCategories && $legacyCategoryIdColumn && vnv_blog_column_exists($db, 'cms_contents', 'id_blog_category');
        $legacyNameSelect = $hasLegacyJoin ? "bc.name" : "NULL";
        $legacySlugSelect = $hasLegacyJoin ? "bc.slug" : "NULL";
        $legacyImageSelect = $hasLegacyJoin && vnv_blog_column_exists($db, 'blog_categories', 'featured_image_url') ? "bc.featured_image_url" : "NULL";
        $categorySelect = $hasCategories
            ? "COALESCE(cc.name, {$legacyNameSelect}) AS category_name, COALESCE(cc.slug, {$legacySlugSelect}) AS category_slug, COALESCE(cc.featured_image_url, {$legacyImageSelect}) AS category_featured_image_url"
            : ($hasLegacyJoin ? "bc.name AS category_name, bc.slug AS category_slug, {$legacyImageSelect} AS category_featured_image_url" : "NULL AS category_name, NULL AS category_slug, NULL AS category_featured_image_url");

        $orderColumn = vnv_blog_first_existing_column($db, 'cms_contents', ['published_at', 'updated_at', 'created_at', 'id']);
        $orderBy = $orderColumn ? "c.{$orderColumn} DESC" : "c.id DESC";

        $postSql = "
            SELECT
                c.*,
                {$routeSelect}
                {$categorySelect}
            FROM cms_contents c
            {$routeJoin}
            {$categoryJoin}
            WHERE " . implode(' AND ', $contentWhere) . "
            GROUP BY c.id
            ORDER BY {$orderBy}
            LIMIT 48
        ";

        $posts = vnv_blog_query($db, $postSql, $contentParams);
    }

    $categoriesById = [];
    $categoriesBySlug = [];
    foreach ($categories as $index => $category) {
        $id = (int)($category['id'] ?? $category['id_blog_category'] ?? 0);
        $slug = (string)($category['slug'] ?? '');
        $categories[$index]['posts_count'] = 0;
        $categories[$index]['dynamic_image_url'] = $category['featured_image_url'] ?? $category['image_url'] ?? $category['cover_image_url'] ?? '';
        if ($id > 0) {
            $categoriesById[$id] = $index;
        }
        if ($slug !== '') {
            $categoriesBySlug[$slug] = $index;
        }
    }

    foreach ($posts as $post) {
        $categoryId = (int)($post['id_cms_category'] ?? $post['id_blog_category'] ?? 0);
        if ($categoryId > 0 && isset($categoriesById[$categoryId])) {
            $categoryIndex = $categoriesById[$categoryId];
            $categories[$categoryIndex]['posts_count']++;
            if (empty($categories[$categoryIndex]['dynamic_image_url']) && !empty($post['featured_image_url'])) {
                $categories[$categoryIndex]['dynamic_image_url'] = $post['featured_image_url'];
            }
            continue;
        }

        $categorySlug = (string)($post['category_slug'] ?? '');
        if ($categorySlug !== '' && isset($categoriesBySlug[$categorySlug])) {
            $categoryIndex = $categoriesBySlug[$categorySlug];
            $categories[$categoryIndex]['posts_count']++;
            if (empty($categories[$categoryIndex]['dynamic_image_url']) && !empty($post['featured_image_url'])) {
                $categories[$categoryIndex]['dynamic_image_url'] = $post['featured_image_url'];
            }
        }
    }

    $categories = array_values(array_filter($categories, static function ($category) {
        return (int)($category['posts_count'] ?? 0) > 0 || !empty($category['slug']);
    }));

    usort($categories, static function ($a, $b) {
        $countComparison = ((int)($b['posts_count'] ?? 0)) <=> ((int)($a['posts_count'] ?? 0));
        if ($countComparison !== 0) {
            return $countComparison;
        }

        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $featuredPosts = array_slice($posts, 0, 3);
    $recentPosts = array_slice($posts, 3, 9);
} catch (Throwable $e) {
    $featuredPosts = [];
    $recentPosts = [];
}

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'categories' => $categories,
    'featured_posts' => $featuredPosts,
    'recent_posts' => $recentPosts,
    'meta_title' => 'Tips and Advices | VNV Events',
    'meta_description' => 'Ideas, planning tips, and expert guidance for stress-free luxury events in South Florida.',
]);
exit;
