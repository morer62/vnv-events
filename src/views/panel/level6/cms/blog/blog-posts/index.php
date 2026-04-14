<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $db->query("
        SELECT c.*, t.name AS template_name, t.slug AS template_slug, bc.name AS blog_category_name, bc.slug AS blog_category_slug
        FROM cms_contents c
        LEFT JOIN cms_templates t ON t.id = c.id_template
        LEFT JOIN blog_categories bc ON bc.id = c.id_blog_category
        WHERE c.type = 'post' AND c.language = 'en'
        ORDER BY c.id DESC
    ");
    $posts = $db->fetchAll() ?: [];

    foreach ($posts as $post) {
        $post->main_route = $routesRepository->getMainRouteByContent((int)$post->id, $post->language ?? 'en');
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Blog Posts",
        "posts" => $posts,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}