<?php

use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Services\HashService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Utils\FormatPhone;
use Google\Client;            // ✅ IMPORTANTE
use Google\Service\Oauth2;

Cors::handle();
$router = new Router();

$router->post(function () {
    $name        = trim($_POST["name"] ?? "");
    $lastname    = trim($_POST["lastname"] ?? "");
    $email       = trim($_POST["email"] ?? "");
    $password    = $_POST["password"] ?? "";
    $phone       = $_POST["phone"] ?? "";
    $level       = isset($_POST["level"]) ? (int) $_POST["level"] : 0;
    $googleToken = $_POST["google_token"] ?? "";

    $userRepo = new UserRepository();

    try {
        if ($googleToken) {
            // 🔹 Signup con Google
            $client = new Client(); // ✅ Namespace correcto
            $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
            $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
            $client->setAccessToken(['access_token' => $googleToken]); // ✅ Pasar array

            $googleService = new Oauth2($client);

            try {
                $data = $googleService->userinfo->get();
            } catch (\Throwable $e) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Google token invalid or expired"
                ]);
            }

            $email    = $data->email ?? null;
            $name     = $data->givenName ?? '';
            $lastname = $data->familyName ?? '';

            if (!$email) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Unable to get email from Google account"
                ]);
            }

            $existing = $userRepo->getOne(["email" => $email]);
            if ($existing) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Email already registered"
                ]);
            }

            if ($level === 0) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Account type (level) is required"
                ]);
            }

            $freeDays = intval($_ENV["FREE_MEMBERSHIP_DAYS"] ?? 15);
            $dueDate  = date('Y-m-d', strtotime("+$freeDays days"));

            $userRepo->add([
                'name'                 => $name,
                'lastname'             => $lastname,
                'email'                => $email,
                'password'             => '', // Google: sin password
                'phone'                => FormatPhone::formatPhone($phone),
                'phone_code'           => '',
                'phone_validation'     => 1,
                'membership_due_date'  => $dueDate,
                'level'                => $level,
                'google_id'            => $data->id ?? null,
                'google_token'         => json_encode(['access_token' => $googleToken]),
            ]);

            $user = $userRepo->getOne(["email" => $email]);

            return JsonResponse::createResponse([
                "success" => true,
                "user" => [
                    "id"       => $user->id,
                    "name"     => $user->name,
                    "lastname" => $user->lastname,
                    "email"    => $user->email,
                    "level"    => $user->level,
                ]
            ]);

        } else {
            // 🔹 Signup normal
            if ($level === 0) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Account type (level) is required"
                ]);
            }

            if (!$name || !$lastname || !$email || !$password) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Name, lastname, email, and password are required"
                ]);
            }

            $existing = $userRepo->getOne(["email" => $email]);
            if ($existing) {
                return JsonResponse::createResponse([
                    "success" => false,
                    "message" => "Email already registered"
                ]);
            }

            $freeDays = intval($_ENV["FREE_MEMBERSHIP_DAYS"] ?? 15);
            $dueDate  = date('Y-m-d', strtotime("+$freeDays days"));

            $userRepo->add([
                'name'                 => $name,
                'lastname'             => $lastname,
                'email'                => $email,
                'password'             => HashService::hashPassword($password),
                'phone'                => FormatPhone::formatPhone($phone),
                'phone_code'           => '',
                'phone_validation'     => 1,
                'membership_due_date'  => $dueDate,
                'level'                => $level
            ]);

            $user = $userRepo->getOne(["email" => $email]);

            return JsonResponse::createResponse([
                "success" => true,
                "user" => [
                    "id"       => $user->id,
                    "name"     => $user->name,
                    "lastname" => $user->lastname,
                    "email"    => $user->email,
                    "level"    => $user->level,
                ]
            ]);
        }

    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    }
});

$router->run();
