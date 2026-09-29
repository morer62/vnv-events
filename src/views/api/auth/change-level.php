<?php


use App\Services\ApiAuthService;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

$router = new RouterApi();
$router->post(function (Request $request) {

    $payload = ApiAuthService::bodyFromJsonOrPost($request);
    $user = ApiAuthService::getAuthenticatedUser($request, $payload);

    if (!$user) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    $level = (int)($payload["level"] ?? 0);
    if ($level !== (int)$user->getLevel()) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Account roles are managed by VNV Events administrators."
        ], 403);
    }

    return JsonResponse::createResponse([
        "success" => true,
        "message" => "Account role unchanged",
        "user" => ApiAuthService::userPayload($user),
    ]);
});


$router->run();
