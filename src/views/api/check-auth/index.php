<?php

use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\JsonResponse;

Cors::handle();

$user = LoginService::getSession();

if ($user) {
    JsonResponse::createResponse([
        'success' => true,
        'authenticated' => true,
        'user' => [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'lastname' => $user->getLastname(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone()
        ]
    ])->handle();
} else {
    JsonResponse::createResponse([
        'success' => false,
        'authenticated' => false,
        'user' => null
    ])->handle();
}