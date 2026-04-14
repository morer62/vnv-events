<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"               => "CMS",
        "totalPages"          => $contentsRepository->countByType('page'),
        "totalPosts"          => $contentsRepository->countByType('post'),
        "totalBlogCategories" => 0,
        "totalLocationPages"  => 0,
        "totalTemplates"      => count($templatesRepository->getAll()),
        "totalPublishedPages" => $contentsRepository->countByTypeAndStatus('page', 'PUBLISHED'),
        "totalPublishedPosts" => $contentsRepository->countByTypeAndStatus('post', 'PUBLISHED'),
        "totalDraftPages"     => $contentsRepository->countByTypeAndStatus('page', 'DRAFT'),
        "totalDraftPosts"     => $contentsRepository->countByTypeAndStatus('post', 'DRAFT'),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}