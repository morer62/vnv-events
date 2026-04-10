<?php


use App\Repositories\UserRepository;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

$router = new RouterApi();
$router->post(function (Request $request) {

    $headers = $request->getHeaders();
    $userRepo = new UserRepository();
    $payload = $request->getBody();
    $token = $headers['Authorization'] ?? $headers["authorization"] ?? null;
    if (is_null($token)) {
        return JsonResponse::createResponse([
            "message" => "Unauthorized"
        ], 401);
    }

    $token = substr($token, 7);

    $user = $userRepo->getOne(["api_token" => $token]);

    if (is_null($user)) {
        return JsonResponse::createResponse([
            "message" => "Unauthorized"
        ], 401);
    }

    $userRepo->update([
        "level" => $payload["level"]
    ], [
        "id" => $user->id,
    ]);

    return JsonResponse::createResponse([
        "message" => "Level changed successfully"
    ]);
});


$router->run();