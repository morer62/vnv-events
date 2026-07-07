<?php

use App\Entity\User;
use App\Repositories\UserRepository;
use App\Services\ApiAuthService;
use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\ErrorLogging;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

Cors::handle();

$router = new RouterApi();

$router->post(function (Request $request) {
    $userRepo = new UserRepository();
    $body = ApiAuthService::bodyFromJsonOrPost($request);
    $token = ApiAuthService::getToken($request, $body);

    $user = LoginService::validateToken($token);

    if (!$user instanceof User) {
        ErrorLogging::warning('Unauthorized token for expo token');
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $expoPush = trim((string)(
        $body['expo_push_token']
        ?? $body['expo_token']
        ?? $body['expoPushToken']
        ?? $body['pushToken']
        ?? ''
    ));

    if ($expoPush === '') {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'expo_push_token is required',
        ], 400);
    }

    if (!ApiAuthService::isValidExpoToken($expoPush)) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Invalid Expo push token format',
        ], 400);
    }

    $userRepo->updateExpoToken($user->getId(), $expoPush);

    return JsonResponse::createResponse([
        'success' => true,
        'message' => 'Expo push token updated successfully',
        'user_id' => (int)$user->getId(),
        'expo_token_saved' => true,
    ]);
});

$router->run();
