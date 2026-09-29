<?php

use App\Repositories\Connection;
use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $user = ApiAuthService::getAuthenticatedUser();
    if (!$user) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $db = new Connection();
    $db->query("
        SELECT
            c.id,
            c.title,
            c.slug,
            c.excerpt,
            c.meta_description,
            c.featured_image_url,
            c.published_at,
            COALESCE(cc.name, 'Event inspiration') AS category_name,
            COALESCE(cc.slug, 'event-inspiration') AS category_slug,
            COALESCE(r.route, CONCAT('/blog/', c.slug, '/')) AS public_route
        FROM cms_contents c
        LEFT JOIN cms_categories cc
          ON cc.id = c.id_cms_category
         AND cc.id_owner = 2
         AND cc.site_key = 'vnvevents'
        LEFT JOIN cms_routes r
          ON r.id_content = c.id
         AND r.id_owner = 2
         AND r.site_key = 'vnvevents'
         AND r.is_main = 1
         AND (r.status IS NULL OR r.status = 'ACTIVE')
        WHERE c.id_owner = 2
          AND c.site_key = 'vnvevents'
          AND c.language = 'en'
          AND c.status = 'PUBLISHED'
          AND (
            LOWER(COALESCE(c.type, '')) IN ('post', 'blog', 'blog_post')
            OR LOWER(COALESCE(c.content_type, '')) IN ('post', 'blog', 'blog_post')
          )
        GROUP BY c.id
        ORDER BY COALESCE(c.published_at, c.updated_at, c.created_at) DESC, c.id DESC
        LIMIT 48
    ");
    $articles = $db->fetchAll() ?: [];

    $categories = [];
    $payload = [];
    foreach ($articles as $index => $article) {
        $categorySlug = (string)($article->category_slug ?? 'event-inspiration');
        if (!isset($categories[$categorySlug])) {
            $categories[$categorySlug] = [
                'slug' => $categorySlug,
                'name' => (string)($article->category_name ?? 'Event inspiration'),
                'count' => 0,
            ];
        }
        $categories[$categorySlug]['count']++;

        $payload[] = [
            'id' => (int)$article->id,
            'title' => (string)$article->title,
            'slug' => (string)$article->slug,
            'excerpt' => trim((string)($article->excerpt ?: $article->meta_description ?: 'A fresh idea for planning a memorable event.')),
            'image_url' => (string)($article->featured_image_url ?? ''),
            'category' => [
                'slug' => $categorySlug,
                'name' => (string)($article->category_name ?? 'Event inspiration'),
            ],
            'route' => ltrim((string)$article->public_route, '/'),
            'published_at' => (string)($article->published_at ?? ''),
            'is_featured' => $index < 3,
        ];
    }

    return JsonResponse::createResponse([
        'success' => true,
        'data' => [
            'categories' => array_values($categories),
            'articles' => $payload,
        ],
    ]);
});

$router->run();
