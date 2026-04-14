<?php

use App\Repositories\StoreProductsRepository;
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

    $productsRepo = new StoreProductsRepository();
    $audiencesRepo = new StoreProductsAudiencesRepository();
    $mealStylesRepo = new StoreProductsMealStylesRepository();

    $products = [];

    if ($audience !== '') {
        $products = $audiencesRepo->getProductsByAudience($audience) ?: [];
    }

    if ($mealStyle !== '') {
        $styleProducts = $mealStylesRepo->getProductsByMealStyle($mealStyle) ?: [];

        if ($audience !== '') {
            $audienceIds = array_map(function ($p) {
                return (int)(is_object($p) ? $p->id : $p['id']);
            }, $products);

            $styleProducts = array_filter($styleProducts, function ($p) use ($audienceIds) {
                $id = (int)(is_object($p) ? $p->id : $p['id']);
                return in_array($id, $audienceIds, true);
            });

            $products = array_values($styleProducts);
        } else {
            $products = $styleProducts;
        }
    }

    $productIds = array_values(array_unique(array_map(function ($p) {
        return (int)(is_object($p) ? $p->id : $p['id']);
    }, $products ?: [])));

    $formattedProducts = [];

    foreach ($productIds as $productId) {
        $product = $productsRepo->getFullProductDetails($productId);

        if (!$product) {
            continue;
        }

        $productAudiences = $audiencesRepo->getAudienceTypesByProduct($productId) ?: [];
        $productMealStyles = $mealStylesRepo->getMealStylesByProduct($productId) ?: [];

        $categories = [];
        if (!empty($product->categories) && is_array($product->categories)) {
            $categories = array_map(function ($category) {
                if (is_object($category)) {
                    return [
                        "id" => isset($category->id) ? (int)$category->id : 0,
                        "name" => $category->name ?? ''
                    ];
                }

                if (is_array($category)) {
                    return [
                        "id" => isset($category['id']) ? (int)$category['id'] : 0,
                        "name" => $category['name'] ?? ''
                    ];
                }

                return [
                    "id" => 0,
                    "name" => (string)$category
                ];
            }, $product->categories);
        }

        $attributesGrouped = [];
        if (!empty($product->attributes_grouped) && is_array($product->attributes_grouped)) {
            foreach ($product->attributes_grouped as $attributeName => $values) {
                $attributesGrouped[$attributeName] = array_values(array_map(function ($value) {
                    return (string)$value;
                }, is_array($values) ? $values : []));
            }
        }

        $nutrition = null;
        if (!empty($product->nutrition) && is_object($product->nutrition)) {
            $nutrition = [
                "calories" => $product->nutrition->calories ?? '',
                "protein" => $product->nutrition->protein ?? '',
                "carbohydrates" => $product->nutrition->carbohydrates ?? '',
                "fat" => $product->nutrition->fat ?? '',
                "fiber" => $product->nutrition->fiber ?? '',
                "sugar" => $product->nutrition->sugar ?? '',
                "sodium" => $product->nutrition->sodium ?? '',
                "serving_size" => $product->nutrition->serving_size ?? '',
                "ingredients" => $product->nutrition->ingredients ?? '',
            ];
        } elseif (!empty($product->nutrition) && is_array($product->nutrition)) {
            $nutrition = [
                "calories" => $product->nutrition['calories'] ?? '',
                "protein" => $product->nutrition['protein'] ?? '',
                "carbohydrates" => $product->nutrition['carbohydrates'] ?? '',
                "fat" => $product->nutrition['fat'] ?? '',
                "fiber" => $product->nutrition['fiber'] ?? '',
                "sugar" => $product->nutrition['sugar'] ?? '',
                "sodium" => $product->nutrition['sodium'] ?? '',
                "serving_size" => $product->nutrition['serving_size'] ?? '',
                "ingredients" => $product->nutrition['ingredients'] ?? '',
            ];
        }

        $formattedProducts[] = [
            "id" => (int)$product->id,
            "name" => $product->name ?? '',
            "slug" => $product->slug ?? '',
            "sku" => $product->sku ?? '',
            "short_description" => $product->short_description ?? '',
            "description" => $product->description ?? '',
            "price" => isset($product->price) ? (float)$product->price : 0,
            "main_image" => $product->main_image ?? '',
            "stock_quantity" => isset($product->stock_quantity) ? (int)$product->stock_quantity : 0,
            "min_purchase_qty" => isset($product->min_purchase_qty) ? (int)$product->min_purchase_qty : 1,
            "max_purchase_qty" => isset($product->max_purchase_qty) && $product->max_purchase_qty !== null
                ? (int)$product->max_purchase_qty
                : null,
            "is_featured" => isset($product->is_featured) ? (int)$product->is_featured : 0,
            "categories" => $categories,
            "audiences" => array_values($productAudiences),
            "meal_styles" => array_values($productMealStyles),
            "attributes_grouped" => $attributesGrouped,
            "nutrition" => $nutrition
        ];
    }

    echo json_encode([
        "success" => true,
        "products" => $formattedProducts
    ]);
});

$router->run();