<?php

use App\Repositories\Connection;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$db = new Connection();
$siteKey = strtolower(trim(SiteContext::siteKey()));
$categories = [];
$posts = [];
$featuredPosts = [];
$recentPosts = [];

function vnv_blog_table_exists(Connection $db, string $table): bool
{
    try {
        $result = $db->query("SHOW TABLES LIKE :table_name", [':table_name' => $table]);
        return !empty($result);
    } catch (Throwable $e) {
        return false;
    }
}

function vnv_blog_column_exists(Connection $db, string $table, string $column): bool
{
    try {
        $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE :column_name", [':column_name' => $column]);
        return !empty($result);
    } catch (Throwable $e) {
        return false;
    }
}

function vnv_blog_first_existing_column(Connection $db, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (vnv_blog_column_exists($db, $table, $column)) {
            return $column;
        }
    }

    return null;
}

try {
    $hasCategories = vnv_blog_table_exists($db, 'blog_categories');
    $hasContents = vnv_blog_table_exists($db, 'cms_contents');
    $hasRoutes = vnv_blog_table_exists($db, 'cms_routes');

    if ($hasCategories) {
        $categoryWhere = [];
        $categoryParams = [];

        if (vnv_blog_column_exists($db, 'blog_categories', 'status')) {
            $categoryWhere[] = "LOWER(COALESCE(status, 'active')) IN ('active', 'published', '1')";
        }

        if ($siteKey !== '' && vnv_blog_column_exists($db, 'blog_categories', 'site_key')) {
            $categoryWhere[] = "(site_key IS NULL OR site_key = '' OR LOWER(site_key) IN (:category_site_key, 'shared', 'global', 'all_sites'))";
            $categoryParams[':category_site_key'] = $siteKey;
        }

        $categoryOrderColumn = vnv_blog_first_existing_column($db, 'blog_categories', ['sort_order', 'display_order', 'position', 'name']);
        $categoryOrder = $categoryOrderColumn ? " ORDER BY {$categoryOrderColumn} ASC" : " ORDER BY id_blog_category ASC";
        $categorySql = "SELECT * FROM blog_categories";
        if (!empty($categoryWhere)) {
            $categorySql .= " WHERE " . implode(' AND ', $categoryWhere);
        }
        $categorySql .= $categoryOrder;

        $categories = $db->query($categorySql, $categoryParams) ?: [];
    }

    if ($hasContents) {
        $contentWhere = ["c.language = 'en'"];
        $contentParams = [];

        if (vnv_blog_column_exists($db, 'cms_contents', 'status')) {
            $contentWhere[] = "LOWER(COALESCE(c.status, 'published')) IN ('published', 'active', '1')";
        }

        if (vnv_blog_column_exists($db, 'cms_contents', 'approval_status')) {
            $contentWhere[] = "LOWER(COALESCE(c.approval_status, 'approved')) IN ('approved', 'published', 'active', '')";
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
            $routeJoin = "LEFT JOIN cms_routes r ON r.content_id = c.id AND r.language = 'en' AND (r.is_primary = 1 OR r.route = CONCAT('/blog/', c.slug, '/'))";
        }

        $categoryJoin = $hasCategories ? "LEFT JOIN blog_categories bc ON bc.id_blog_category = c.id_blog_category" : "";
        $categorySelect = $hasCategories ? "bc.name AS category_name, bc.slug AS category_slug, bc.featured_image_url AS category_featured_image_url" : "NULL AS category_name, NULL AS category_slug, NULL AS category_featured_image_url";

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

        $posts = $db->query($postSql, $contentParams) ?: [];
    }

    $categoriesById = [];
    foreach ($categories as $index => $category) {
        $id = (int)($category['id_blog_category'] ?? $category['id'] ?? 0);
        $categories[$index]['posts_count'] = 0;
        $categories[$index]['dynamic_image_url'] = $category['featured_image_url'] ?? $category['image_url'] ?? $category['cover_image_url'] ?? '';
        if ($id > 0) {
            $categoriesById[$id] = $index;
        }
    }

    foreach ($posts as $post) {
        $categoryId = (int)($post['id_blog_category'] ?? 0);
        if ($categoryId > 0 && isset($categoriesById[$categoryId])) {
            $categoryIndex = $categoriesById[$categoryId];
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
    $categories = [];
    $featuredPosts = [];
    $recentPosts = [];
}

return TemplateResponse::render(__DIR__ . '/index.twig', [
    'categories' => $categories,
    'featured_posts' => $featuredPosts,
    'recent_posts' => $recentPosts,
    'meta_title' => 'Tips and Advices | VNV Events',
    'meta_description' => 'Ideas, planning tips, and expert guidance for stress-free luxury events in South Florida.',
]);
