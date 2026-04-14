<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid page ID.";
        exit;
    }

    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $page = $contentsRepository->getOneWithTemplate($id);

    if (!$page) {
        echo "Page not found.";
        exit;
    }

    if (($page->type ?? '') !== 'page' && ($page->type ?? '') !== 'post') {
        echo "Invalid content type for preview.";
        exit;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Preview CMS Content",
        "page"  => $page,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}