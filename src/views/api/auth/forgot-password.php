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
        $success = $forgotPasswordService->sendResetLink($email);

        if ($success) {
            return JsonResponse::createResponse([
                "success" => true,
                "message" => "If an account exists with that email, a reset link has been sent."
            ]);
        } else {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "An error occurred while sending the reset link. Please try again."
            ]);
        }
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "An error occurred: " . $e->getMessage()
        ]);
    }
});

$router->run();