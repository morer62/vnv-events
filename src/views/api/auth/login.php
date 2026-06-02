<?php

use App\Entity\User;
use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Services\AppleService\AppleSignInService;
use App\Services\ApiAuthService;
use App\Services\GoogleAuthService;
use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use Google\Service\Oauth2;

Cors::handle();

$router = new Router();

/**
 * @throws \Google\Service\Exception
 */
function loginGoogle($email, $googleToken): JsonResponse|array
{

    $repo = new UserRepository();
    $googleAuthService = new GoogleAuthService();

    $userPayload =  $googleAuthService->verifyGoogleIdToken($googleToken);

    $user = $repo->getOne([
        "google_id" => $userPayload['user_id']
    ]);

    if (!$user) {
        $user = $repo->getOne([
            "email" => $userPayload['email']
        ]);
    }

    if (!$user) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "No account found with this Google account. Please sign up first."
        ], 404);
    }

    if ((int)$user->is_active === 0) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Your account is inactive."
        ]);
    }

    // Guardar info de Google
    $repo->updateData($user->id, [
        "google_id" => $userPayload['user_id'],
        "google_token" => $googleToken
    ]);

    // Cargar permisos
    $roleRepo = new UserRolesRepository();
    $permissions = $roleRepo->getUserRoleAndPermissions($user->id);

    return [$user, $permissions];
}

function loginApple($email, $identityToken): JsonResponse|array
{
    $appleSignInService = new AppleSignInService("", "");
    $userRepo = new UserRepository();
    $roleRepo = new UserRolesRepository();

    $payload = null;
    try {
        $payload = $appleSignInService->verifyAppleIdentityToken($identityToken);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 401);
    }

    $user = $userRepo->getOne(["apple_id" => $payload->sub]);

    if (!$user) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "No account found with this Apple account. Please sign up first."
        ], 404);
    }

    if ((int)$user->is_active === 0) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Your account is inactive."
        ]);
    }

    // Cargar permisos
    $permissions = $roleRepo->getUserRoleAndPermissions($user->id);
    return [$user, $permissions];
}

function setSession($user, $permissions): User
{

    // Crear sesión interna
    $userEntity = new User(
        $user->id,
        $user->name,
        $user->lastname,
        $user->email,
        $user->password,
        $user->phone,
        $user->phone_validation,
        $user->membership_due_date,
        $user->level,
        $user->phone_code,
        $user->id_owner,
        $permissions,
        $user->allow_chat_with_clients ?? 0,
        $user->membership_type ?? 'FREE',
        $user->is_active ?? 1,
        $user->google_id ?? null,
        $user->google_token ?? null
    );
    LoginService::setSession($userEntity);

    return $userEntity;
}

$router->post(function () {
    $body = ApiAuthService::bodyFromJsonOrPost();
    $email = trim($body["email"] ?? "");
    $password = $body["password"] ?? "";
    $googleToken = $body["google_token"] ?? "";
    $appleToken = $body["apple_credentials"] ?? "";
    $userEntity = null;

    try {
        $repo = new UserRepository();

        if ($googleToken) {

            $data = loginGoogle($email, $googleToken);

            if ($data instanceof JsonResponse) return $data;

            [$user, $permissions] = $data;
            $userEntity = setSession($user, $permissions);

        } else if ($appleToken) {
            $data = loginApple($email, $appleToken);
            if ($data instanceof JsonResponse) return $data;

            [$user, $permissions] = $data;
            $userEntity = setSession($user, $permissions);

        } else {
            // 🔹 Login normal con email y password
            if (!$email || !$password) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Email and password are required"
                ]);
            }

            $service = new LoginService();
            $service->authenticate($email, $password);
            $userEntity = LoginService::getSession();
        }

        // Generar token API
        $token = bin2hex(random_bytes(32));
        $repo->updateApiToken($userEntity->getId(), $token);

        // Guardar expo_token si viene
        $expo_token = trim((string)($body["expo_token"] ?? ($body["expo_push_token"] ?? "")));
        $expoTokenSaved = false;
        if ($expo_token !== "" && strtolower($expo_token) !== "undefined") {
            if (ApiAuthService::isValidExpoToken($expo_token)) {
                $repo->updateExpoToken($userEntity->getId(), $expo_token);
                $expoTokenSaved = true;
            }
        }

        return JsonResponse::createResponse([
            "success" => true,
            "token" => $token,
            "api_token" => $token,
            "expo_token_saved" => $expoTokenSaved,
            "user" => ApiAuthService::userPayload($userEntity),
        ]);

    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    }
});

$router->run();
