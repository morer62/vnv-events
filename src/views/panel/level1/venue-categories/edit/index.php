<?php

use App\Repositories\VenueCategoriesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET["id"];
    $repo = new VenueCategoriesRepository();

    $data = $repo->getOne(criteriaVal: [
        "id" => $id
    ]);

    if (is_null($data)) {
        MessageUtil::setMessage("Category not found");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $data
    ]);
});

$router->post(function () {
    $id = $_GET["id"];
    $repo = new VenueCategoriesRepository();

    $data = $repo->getOne(criteriaVal: [
        "id" => $id
    ]);

    if (is_null($data)) {
        MessageUtil::setMessage("Category not found");
        LocationUtils::redirectInternal("panel/venue-categories/home");
    }

    $repo->update(data: [
        'name' => $_POST["name"],
        'description' => $_POST["description"],
    ], criteriaVals: [
        'id' => intval($id)
    ]);

    MessageUtil::setMessage("Category updated");
    LocationUtils::redirectInternal("panel/venue-categories/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
