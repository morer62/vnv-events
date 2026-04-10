<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Repositories\WhatsappRepository;

Cors::handle();

$router = new Router();

$router->post(function () {
    $client_id = $_POST["client_id"] ?? null;
    $name = trim($_POST["name"] ?? '');

    if (!$client_id) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Missing client_id"
        ]);
    }

    $repo = new WhatsappRepository();
    $result = $repo->update(["name" => $name], ["id" => $client_id]);

    return JsonResponse::createResponse([
        "success" => $result,
        "message" => $result ? "Name updated" : "Failed to update name"
    ]);
});

$router->run();
