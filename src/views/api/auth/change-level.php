<?php


use App\Services\ApiAuthService;
use App\Repositories\UserRepository;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

$router = new RouterApi();
$router->post(function (Request $request) {

    $userRepo = new UserRepository();
    $payload = ApiAuthService::bodyFromJsonOrPost($request);
    $user = ApiAuthService::getAuthenticatedUser($request, $payload);

    if (!$user) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Unauthorized"
        ], 401);
    }

    $currentLevel = (int)$user->getLevel();
    $level = (int)($payload["level"] ?? 0);
    $allowedLevels = $currentLevel === 1 ? [1, 4, 5] : [4, 5];
    if (!in_array($level, $allowedLevels, true)) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Mobile level changes cannot create or switch into business-owner levels. Use web signup/admin for business accounts."
        ], 403);
    }

    $userRepo->update([
        "level" => $level
    ], [
        "id" => $user->getId(),
    ]);

    $user->setLevel($level);

    return JsonResponse::createResponse([
        "success" => true,
        "message" => "Level changed successfully",
        "user" => ApiAuthService::userPayload($user),
    ]);
});


$router->run();
