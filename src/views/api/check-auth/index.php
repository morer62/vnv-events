<?php

use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;

Cors::handle();

$user = ApiAuthService::getAuthenticatedUser();

if ($user) {
    JsonResponse::createResponse([
        'success' => true,
        'authenticated' => true,
        'user' => ApiAuthService::userPayload($user),
    ])->handle();
} else {
    JsonResponse::createResponse([
        'success' => false,
        'authenticated' => false,
        'user' => null
    ])->handle();
}
