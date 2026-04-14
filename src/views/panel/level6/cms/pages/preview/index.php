<?php

use App\Repositories\CmsPagesRepository;
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

    $pagesRepository = new CmsPagesRepository();
    $pagesRepository->db = $db;

    $page = $pagesRepository->getOneWithCategoryAndTemplate($id);

    if (!$page) {
        echo "Page not found.";
        exit;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Preview CMS Page",
        "page" => $page,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}