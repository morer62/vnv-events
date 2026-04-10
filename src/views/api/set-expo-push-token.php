<?php

use App\Entity\User;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\ErrorLogging;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

$router = new RouterApi();


$router->post(function (Request $request) {
    $userRepo = new UserRepository();
    $body = $request->getBody();
    $headers = $request->getHeaders();
    $token = $headers['Authorization'] ?? $headers['authorization'] ?? "";

    $user = LoginService::validateToken($token);

    if (!$user instanceof User) {
        ErrorLogging::log(new Exception("Unauthorized token for expo token"));
        return JsonResponse::createResponse([
            "message" => "Unauthorized"
        ], 401);
    }

    $userRepo->updateExpoToken($user->getId(), $body['expo_push_token']);

    return JsonResponse::createResponse([
       "message" => "Expo push token updated successfully"
    ]);
});

$router->run();
