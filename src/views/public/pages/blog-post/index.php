<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Utils\TemplateResponse;

$db = new Connection();

$contentsRepository = new CmsContentsRepository();
$contentsRepository->db = $db;

$routesRepository = new CmsRoutesRepository();
$routesRepository->db = $db;

$blogCategoriesRepository = new BlogCategoriesRepository();
$blogCategoriesRepository->db = $db;

$url = $_GET['url'] ?? '';
$normalizedRoute = $routesRepository->normalizeRoute($url);

$route = $routesRepository->getByRoute($normalizedRoute, 'en');

if (!$route) {
    http_response_code(404);
    echo "Post not found";
    exit;
}

$post = $contentsRepository->getOneWithTemplate((int)$route->id_content);

if (!$post) {
    http_response_code(404);
    echo "Post not found";
    exit;
}

if (($post->type ?? '') !== 'post') {
    http_response_code(404);
    echo "Post not found";
    exit;
}

if (($post->status ?? '') !== 'PUBLISHED') {
    http_response_code(404);
    echo "Post not found";
    exit;
}

$contentJson = [];
if (!empty($post->content_json)) {
    $decoded = json_decode($post->content_json, true);
    if (is_array($decoded)) {
        $contentJson = $decoded;
    }
}

$category = null;
if (!empty($post->id_blog_category)) {
    $category = $blogCategoriesRepository->getOne([
        'id' => (int)$post->id_blog_category
    ]);
}

TemplateResponse::render(__DIR__ . "/index.twig", [
    "post" => $post,
    "route" => $route,
    "category" => $category,
    "content_json" => $contentJson,
    "show_whatsapp" => true,
]);