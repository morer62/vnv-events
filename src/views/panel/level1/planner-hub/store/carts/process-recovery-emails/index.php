<?php

use App\Services\LoginService;
use App\Services\StoreAbandonedCartRecoveryService;
use App\Utils\Router;

$router = new Router();

$router->post(function () {
    header('Content-Type: application/json');

    $user = LoginService::getSession();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        return;
    }

    try {
        $result = StoreAbandonedCartRecoveryService::processPending(10, 30);

        echo json_encode([
            'success' => true,
            'message' => 'Recovery batch processed successfully.',
            'data' => $result
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
});

$router->run();