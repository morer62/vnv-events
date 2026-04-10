<?php

use App\Services\ZipValidationService;
use App\Utils\JsonResponse;
use App\Utils\Router;

$router = new Router();

$router->post(function () {
    $slug = $_POST["slug"] ?? "";
    $categoryId = $_POST["category_id"] ?? 0;

    if (!$slug || !$categoryId) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "City and category are required."
        ]);
    }

    $validator = new ZipValidationService();
    $isTaken = $validator->isCityTaken($slug, intval($categoryId));

    return JsonResponse::createResponse([
        "success" => true,
        "valid" => $isTaken == false
    ]);
});

$router->run();
