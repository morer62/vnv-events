<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->post(function () {
    $orderId = $_POST["order_id"] ?? null;
    $userId = $_POST["user_id"] ?? null;

    if (!$orderId || !$userId) { 
        return (new JsonResponse(["success" => false, "error" => "Missing data"], 400))->handle();

    }

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $exp = time() + (60 * 60 * 24 * 7); // válido por 7 días

    $payload = [
        "order_id" => $orderId,
        "user_id" => $userId,
        "exp" => $exp
    ];

    $hash = hash_hmac("sha256", json_encode($payload), $secret);
    $payload["hash"] = $hash;

    $token = base64_encode(json_encode($payload));

    return (new JsonResponse([
            "success" => true,
            "token" => $token,
            "url" => $_ENV["APP_URL"] . "/order-access.php?token=" . $token
        ]))->handle();
});

$router->run();
