<?php

use App\Repositories\StoreProductsAudiencesRepository;
use App\Repositories\StoreProductsMealStylesRepository;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    header('Content-Type: application/json');

    $audience = trim($_GET['audience'] ?? '');
    $mealStyle = trim($_GET['meal_style'] ?? '');

    if ($audience === '' && $mealStyle === '') {
        echo json_encode([
            "success" => false,
            "message" => "Filters required",
            "products" => []
        ]);
        return;
    }

    $audiencesRepo = new StoreProductsAudiencesRepository();
    $mealStylesRepo = new StoreProductsMealStylesRepository();

    $products = [];

    if ($audience !== '') {
        $products = $audiencesRepo->getProductsByAudience($audience);
    }

    if ($mealStyle !== '') {
        $styleProducts = $mealStylesRepo->getProductsByMealStyle($mealStyle);

        if ($audience !== '') {
            $audienceIds = array_map(function ($p) {
                return (int)(is_object($p) ? $p->id : $p['id']);
            }, $products ?: []);

            $styleProducts = array_filter($styleProducts, function ($p) use ($audienceIds) {
                $id = (int)(is_object($p) ? $p->id : $p['id']);
                return in_array($id, $audienceIds);
            });

            $products = array_values($styleProducts);
        } else {
            $products = $styleProducts;
        }
    }

    echo json_encode([
        "success" => true,
        "products" => $products
    ]);
});

$router->run();