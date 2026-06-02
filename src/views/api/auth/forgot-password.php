<?php

use App\Services\ForgotPasswordService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();
$router = new Router();

$router->post(function () {
    $email = $_POST['email'] ?? '';

    if (!$email) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Email is required"
        ]);
    }

    try {
        $forgotPasswordService = new ForgotPasswordService();
        $result = $forgotPasswordService->sendResetLink($email);

        if ($result === true) {
            return JsonResponse::createResponse([
                "success" => true,
                "message" => "If an account exists with that email, a reset link has been sent."
            ]);
        }

        if ($result === "google_account") {
            return JsonResponse::createResponse([
                "success" => false,
                "oauth_account" => "google",
                "message" => "This account was created with Google. Please sign in with Google instead."
            ]);
        }

        if ($result === "apple_account") {
            return JsonResponse::createResponse([
                "success" => false,
                "oauth_account" => "apple",
                "message" => "This account was created with Apple. Please sign in with Apple instead."
            ]);
        }

        return JsonResponse::createResponse([
            "success" => false,
            "message" => "An error occurred while sending the reset link. Please try again."
        ]);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "An error occurred: " . $e->getMessage()
        ]);
    }
});

$router->run();
