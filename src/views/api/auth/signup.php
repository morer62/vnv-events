<?php

use App\Repositories\ClientsUsersRepository;
use App\Repositories\Connection;
use App\Repositories\UserRepository;
use App\Services\HashService;
use App\Utils\AvomealContext;
use App\Utils\Cors;
use App\Utils\FormatPhone;
use App\Utils\JsonResponse;
use App\Utils\Router;
use Google\Client;
use Google\Service\Oauth2;

Cors::handle();
$router = new Router();

$router->post(function () {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($body)) {
        $body = [];
    }
    $body = array_merge($_POST ?: [], $body);

    $name = trim($body["name"] ?? "");
    $lastname = trim($body["lastname"] ?? "");
    $email = trim($body["email"] ?? "");
    $password = $body["password"] ?? "";
    $phone = $body["phone"] ?? "";
    $level = isset($body["level"]) ? (int)$body["level"] : 5;
    $googleToken = $body["google_token"] ?? "";
    $businessOwnerId = resolveMobileSignupBusinessOwnerId($body);

    $userRepo = new UserRepository();
    $clientsUsersRepo = new ClientsUsersRepository();

    try {
        if ($level !== 5) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Mobile signup is only available for client accounts. Business signup must use the web admin flow."
            ], 403);
        }

        if ($businessOwnerId <= 0) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Business owner context is required for mobile client signup."
            ], 422);
        }

        if ($googleToken) {
            $client = new Client();
            $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
            $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
            $client->setAccessToken(['access_token' => $googleToken]);

            $googleService = new Oauth2($client);

            try {
                $data = $googleService->userinfo->get();
            } catch (\Throwable $e) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Google token invalid or expired"
                ], 401);
            }

            $email = $data->email ?? null;
            $name = $data->givenName ?? '';
            $lastname = $data->familyName ?? '';

            if (!$email) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Unable to get email from Google account"
                ], 422);
            }

            $existing = $userRepo->getOne(["email" => $email]);
            if ($existing) {
                if ((int)$existing->level !== 5) {
                    return JsonResponse::createResponse([
                        "success" => false,
                        "message" => "This email is already registered as a non-client account."
                    ], 409);
                }

                $clientsUsersRepo->create((int)$existing->id, $businessOwnerId);
                return mobileSignupUserResponse($userRepo, $existing, $businessOwnerId, true);
            }

            $userRepo->add([
                'name' => $name,
                'lastname' => $lastname,
                'email' => $email,
                'password' => '',
                'phone' => FormatPhone::formatPhone($phone),
                'phone_code' => '',
                'phone_validation' => 1,
                'membership_due_date' => null,
                'membership_type' => 'FREE',
                'level' => 5,
                'id_owner' => $businessOwnerId,
                'google_id' => $data->id ?? null,
                'google_token' => json_encode(['access_token' => $googleToken]),
            ]);

            $user = $userRepo->getOne(["email" => $email]);
            $clientsUsersRepo->create((int)$user->id, $businessOwnerId);

            return mobileSignupUserResponse($userRepo, $user, $businessOwnerId);
        }

        if (!$name || !$lastname || !$email || !$password) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Name, lastname, email, and password are required"
            ], 422);
        }

        $existing = $userRepo->getOne(["email" => $email]);
        if ($existing) {
            if ((int)$existing->level !== 5) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "This email is already registered as a non-client account."
                ], 409);
            }

            $clientsUsersRepo->create((int)$existing->id, $businessOwnerId);
            return mobileSignupUserResponse($userRepo, $existing, $businessOwnerId, true);
        }

        $userRepo->add([
            'name' => $name,
            'lastname' => $lastname,
            'email' => $email,
            'password' => HashService::hashPassword($password),
            'phone' => FormatPhone::formatPhone($phone),
            'phone_code' => '',
            'phone_validation' => 1,
            'membership_due_date' => null,
            'membership_type' => 'FREE',
            'level' => 5,
            'id_owner' => $businessOwnerId,
        ]);

        $user = $userRepo->getOne(["email" => $email]);
        $clientsUsersRepo->create((int)$user->id, $businessOwnerId);

        return mobileSignupUserResponse($userRepo, $user, $businessOwnerId);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 500);
    }
});

$router->run();

function resolveMobileSignupBusinessOwnerId(array $body): int
{
    $postedOwner = (int)(
        $body['id_user_business']
        ?? $body['id_owner']
        ?? $body['business_id']
        ?? $body['owner_id']
        ?? 0
    );

    if ($postedOwner > 0) {
        return validApiBusinessOwnerId($postedOwner) ? $postedOwner : 0;
    }

    $defaultOwner = AvomealContext::ownerId();
    return validApiBusinessOwnerId($defaultOwner) ? $defaultOwner : 0;
}

function validApiBusinessOwnerId(int $ownerId): bool
{
    $db = new Connection();
    $db->query("SELECT id FROM users WHERE id = :id AND level IN (1, 2) AND is_active = 1 LIMIT 1");
    $db->bind(':id', $ownerId);
    return (bool)$db->fetchOne();
}

function mobileSignupUserResponse(UserRepository $userRepo, object $user, int $businessOwnerId, bool $associatedExisting = false): JsonResponse
{
    $token = bin2hex(random_bytes(32));
    $userRepo->updateApiToken((int)$user->id, $token);

    return JsonResponse::createResponse([
        "success" => true,
        "token" => $token,
        "api_token" => $token,
        "associated_existing_client" => $associatedExisting,
        "user" => [
            "id" => (int)$user->id,
            "name" => $user->name,
            "lastname" => $user->lastname,
            "email" => $user->email,
            "phone" => $user->phone ?? null,
            "level" => (int)$user->level,
            "id_owner" => (int)($user->id_owner ?? $businessOwnerId),
            "id_user_business" => $businessOwnerId,
            "owner_scope_id" => $businessOwnerId,
        ]
    ]);
}
