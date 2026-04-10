<?php

use App\Repositories\StoreProductsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\StoreProductsAudiencesRepository;
use App\Repositories\StoreProductsMealStylesRepository;

$router = new Router();

$router->get(function () {
    $repo = new StoreProductsRepository();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid product.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $product = $repo->getFullProductDetails($id);

    if (!$product) {
        MessageUtil::setMessage("Product not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/products/home");
    }

    $audiencesRepo = new StoreProductsAudiencesRepository();
    $mealStylesRepo = new StoreProductsMealStylesRepository();

    $product->audiences = $audiencesRepo->getAudienceTypesByProduct($id);
    $product->meal_styles = $mealStylesRepo->getMealStylesByProduct($id);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "product" => $product
    ]);
});

$router->run();