<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->post(function () {
    $token = $_POST['token'] ?? '';

    // Validación básica del token
    if ($token !== 'LOGGED-IN') {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Inválid Token'
        ]);
    }

    // Simulación de datos del usuario (reemplazar con datos reales de la base de datos)
    return JsonResponse::createResponse([
        'success' => true,
        'user' => [
            'id' => 12,
            'name' => 'Jonathan',
            'email' => 'jonathan@vnvevents.com',
            'member_id' => 'VNV10023',
            'membershipRenewal' => '2025-12-31',
            'level' => 2
        ]
    ]);
});

$router->run();
