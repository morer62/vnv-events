<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\PlacesUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET["id"];

    $categoryRepo = new ServiceCategoriesRepository();
    $repo = new ServiceRepository();

    $cat = $categoryRepo->getAll();
    $data = $repo->getOne(criteriaVal: [
        "id" => $id
    ]);

    if (is_null($data)) {
        MessageUtil::setMessage("Service not found");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $data,
        "categories" => $cat
    ]);
});

$router->post(function () {
    $id = $_GET["id"];
    $user = LoginService::getSession();
    $repo = new ServiceRepository();

    $data = $repo->getOne(criteriaVal: [
        "id" => $id
    ]);

    if (is_null($data)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $lat = null;
    $long = null;

    try {
        [$lat, $long] = PlacesUtils::getCoordinates($_POST["address"]);
    } catch (Exception) {
        MessageUtil::setMessage("Could not get the coordinates for ". $_POST["address"]);
    }

    $repo->update(data: [
        "name" => $_POST["name"],
        "description" => $_POST["description"],
        "address" => $_POST["address"],
        "lat" => $lat,
        "lng" => $long,
        "category_id" => $_POST["category"],
        "user_id" => $user->getId()
    ], criteriaVals: [
        'id' => intval($id)
    ]);

    MessageUtil::setMessage("Service updated");
    LocationUtils::redirectInternal("panel/service/home");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
