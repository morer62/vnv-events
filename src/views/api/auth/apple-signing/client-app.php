<?php


use App\Repositories\UserRepository;
use App\Services\AppleService\AppleSignInService;
use App\Services\HashService;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

$router = new RouterApi();

$router->post(function (Request $request) {
    $levels = [
        "venue" => 2,
        "vendor" => 3,
        "client" => 5
    ];

    $payload = $request->getBody();
    $appleSignInService = new AppleSignInService("", "");
    $userRepo = new UserRepository();

    $identityToken = $payload["identityToken"];
    $accountType = $payload["accountType"];

    if (!in_array($accountType, array_keys($levels))) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Invalid account type"
        ], 400);
    }

    $level = $levels[$accountType];

    $payloadData = null;

    try {
        $audience = ["host.exp.Exponent", "com.vnvevents.eplannerhub"];
        $payloadData = $appleSignInService->verifyAppleIdentityToken($identityToken);

        if (!in_array($payloadData->aud, $audience)) {
            throw new Exception("Invalid audience");
        }

    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 401);
    }

    $user = $userRepo->getOne(["apple_id" => $payloadData->sub]);

    if ($user) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "User already exists"
        ], 403);
    }

    $id_owner = null;
    $admins = $userRepo->getAllFlexible(["level" => 1]);
    if (count($admins) > 0) {
        $id_owner = $admins[0]->id;
    }

    $days = intval($_ENV['FREE_MEMBERSHIP_DAYS']);
    $dueDate = date('Y-m-d', strtotime("+{$days} days"));

    $userRepo->add([
        'name' => $payloadData->email ?? "",
        'lastname' => '',
        'email' => $payloadData->email,
        'password' => HashService::hashPassword(bin2hex(random_bytes(16))),
        'phone' => '',
        'phone_code' => '',
        'phone_validation' => 1,
        'membership_due_date' => $dueDate,
        'level' => $level,
        'id_owner' => $id_owner,
        'apple_id' => $payloadData->sub
    ]);

    $userId = $userRepo->getLastId();

    $token = bin2hex(random_bytes(32));
    $userRepo->updateApiToken($userId, $token);

    return JsonResponse::createResponse([
        "success" => true,
        "data" => [
            "user" => [
                "name" => $payloadData->email,
                "email" => $payloadData->email,
                "membership_due_date" => $dueDate,
                "level" => $level,
            ],
            "token" => $token
        ]
    ]);

});

$router->run();