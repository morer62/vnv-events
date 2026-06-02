<?php

use App\Repositories\ClientsUsersRepository;
use App\Repositories\Connection;
use App\Repositories\UserRepository;
use App\Services\GoogleAuthService;
use App\Services\HashService;
use App\Utils\AvomealContext;
use App\Utils\JsonResponse;
use App\Utils\Request;
use App\Utils\RouterApi;

$router = new RouterApi();

$router->post(function (Request $request) {
    $payload = $request->getBody();
    $googleAuthService = new GoogleAuthService();
    $userRepo = new UserRepository();
    $clientsUsersRepo = new ClientsUsersRepository();

    $identityToken = $payload["idToken"] ?? '';
    $accountType = $payload["accountType"] ?? 'client';
    $businessOwnerId = resolveGoogleAppSignupBusinessOwnerId($payload);

    if ($accountType !== 'client') {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Mobile signup is only available for final clients. Business signup must use the web admin flow."
        ], 403);
    }

    if ($businessOwnerId <= 0) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Business owner context is required for mobile client signup."
        ], 422);
    }

    try {
        $payloadData = $googleAuthService->verifyGoogleIdToken($identityToken);

        if (is_null($payloadData)) {
            throw new Exception("Invalid token");
        }
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 401);
    }

    $user = $userRepo->getByGoogleIdorEmail($payloadData['user_id'], $payloadData['email']);

    if ($user) {
        if ((int)$user->level !== 5) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "This email is already registered as a non-client account."
            ], 409);
        }

        $clientsUsersRepo->create((int)$user->id, $businessOwnerId);
        return googleAppSignupResponse($user, $businessOwnerId, true);
    }

    $userRepo->add([
        'name' => $payloadData["name"] ?? "",
        'lastname' => '',
        'email' => $payloadData["email"],
        'password' => HashService::hashPassword(bin2hex(random_bytes(16))),
        'phone' => '',
        'phone_code' => '',
        'phone_validation' => 1,
        'membership_due_date' => null,
        'membership_type' => 'FREE',
        'level' => 5,
        'id_owner' => $businessOwnerId,
        'google_id' => $payloadData['user_id'],
        'google_token' => $identityToken
    ]);

    $userId = $userRepo->getLastId();
    $clientsUsersRepo->create((int)$userId, $businessOwnerId);

    $user = $userRepo->getOneWithoutOwnership(['id' => (int)$userId]);
    return googleAppSignupResponse($user, $businessOwnerId);
});

$router->run();

function resolveGoogleAppSignupBusinessOwnerId(array $payload): int
{
    $postedOwner = (int)(
        $payload['id_user_business']
        ?? $payload['id_owner']
        ?? $payload['business_id']
        ?? $payload['owner_id']
        ?? 0
    );

    if ($postedOwner > 0) {
        return validGoogleAppBusinessOwnerId($postedOwner) ? $postedOwner : 0;
    }

    $defaultOwner = AvomealContext::ownerId();
    return validGoogleAppBusinessOwnerId($defaultOwner) ? $defaultOwner : 0;
}

function validGoogleAppBusinessOwnerId(int $ownerId): bool
{
    $db = new Connection();
    $db->query("SELECT id FROM users WHERE id = :id AND level IN (1, 2) AND is_active = 1 LIMIT 1");
    $db->bind(':id', $ownerId);
    return (bool)$db->fetchOne();
}

function googleAppSignupResponse(object $user, int $businessOwnerId, bool $associatedExisting = false): JsonResponse
{
    $token = bin2hex(random_bytes(32));
    (new UserRepository())->updateApiToken((int)$user->id, $token);

    return JsonResponse::createResponse([
        "success" => true,
        "token" => $token,
        "api_token" => $token,
        "associated_existing_client" => $associatedExisting,
        "data" => [
            "user" => [
                "id" => (int)$user->id,
                "name" => $user->name ?? "",
                "email" => $user->email ?? "",
                "membership_due_date" => null,
                "level" => 5,
                "id_owner" => (int)($user->id_owner ?? $businessOwnerId),
                "id_user_business" => $businessOwnerId,
                "owner_scope_id" => $businessOwnerId,
            ],
            "token" => $token
        ]
    ]);
}
