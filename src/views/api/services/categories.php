<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $catRepo = new ServiceCategoriesRepository();
    $limit = $_GET["limit"] ?? 0;

    $cats = $catRepo->getAll(limit: $limit);

    return JsonResponse::createResponse(array_map(function ($cat) {
        return [
            "id" => $cat->id,
            "name" => $cat->service_category,
            "description" => $cat->description,
        ];
    }, $cats));
});

$router->run();
