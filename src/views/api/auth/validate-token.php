<?php

use App\Services\ApiAuthService;
use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->post(function () {
    $body = ApiAuthService::bodyFromJsonOrPost();
    $token = ApiAuthService::getToken(null, $body);

    if ($token === '') {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Token is required',
        ], 400);
    }

    try {
        $user = LoginService::validateToken($token);

        if (!$user) {
            return JsonResponse::createResponse([
                'success' => false,
                'message' => 'Invalid or expired session',
            ], 401);
        }

        if ((int)$user->getIsActive() !== 1) {
            return JsonResponse::createResponse([
                'success' => false,
                'message' => 'Your account is inactive.',
            ], 403);
        }

        return JsonResponse::createResponse([
            'success' => true,
            'user' => ApiAuthService::userPayload($user),
        ]);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
});

$router->run();
