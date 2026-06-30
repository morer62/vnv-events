<?php

use App\Repositories\StoreProductsRepository;
use App\Repositories\StoreCategoriesRepository;
use App\Utils\AvomealContext;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new StoreProductsRepository();
    $categoriesRepo = new StoreCategoriesRepository();
    $ownerId = AvomealContext::ownerId();
    $siteKey = AvomealContext::siteKey();
    $search = trim((string)($_GET['q'] ?? ''));
    $categoryId = isset($_GET['category']) ? max(0, (int)$_GET['category']) : 0;
    $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
    $allowedPerPage = [10, 25, 50, 100];
    if (!in_array($perPage, $allowedPerPage, true)) {
        $perPage = 25;
    }

    $totalProducts = $repo->countOwnerCatalog($ownerId, $search !== '' ? $search : null, $categoryId, $siteKey);
    $totalPages = max(1, (int)ceil($totalProducts / $perPage));
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $paginationPages = range(max(1, $page - 2), min($totalPages, $page + 2));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "products" => $repo->getOwnerCatalogPage($ownerId, $search !== '' ? $search : null, $categoryId, $perPage, $offset, $siteKey),
        "categories" => $categoriesRepo->getActive($ownerId, $siteKey),
        "filters" => [
            "q" => $search,
            "category" => $categoryId,
            "page" => $page,
            "per_page" => $perPage,
            "total" => $totalProducts,
            "total_pages" => $totalPages,
            "from" => $totalProducts > 0 ? $offset + 1 : 0,
            "to" => min($offset + $perPage, $totalProducts),
            "pages" => $paginationPages,
        ],
    ]);
});

$router->run();
