<?php

use App\Repositories\UserRepository;
use App\Services\ForgotPasswordService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $message = $_GET['message'] ?? null;
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'show_social_modal' => $message === 'google_account'
    ]);
});

$router->post(function () {
    try {
        $email = $_POST['email'] ?? '';

        if (!$email) {
            MessageUtil::setMessage("Email is required");
            LocationUtils::redirectInternal("forgot-password");
        }

        $forgotPasswordService = new ForgotPasswordService();
        $result = $forgotPasswordService->sendResetLink($email);

        if ($result === "google_account") {
            return TemplateResponse::render(__DIR__ . "/index.twig", [
                'show_social_modal' => true,
                'social_account_type' => 'google'
            ]);
        }
        
        if ($result === "apple_account") {
            return TemplateResponse::render(__DIR__ . "/index.twig", [
                'show_social_modal' => true,
                'social_account_type' => 'apple'
            ]);
        }

        if ($result === true) {
            MessageUtil::setMessage("If an account exists with that email, a reset link has been sent.");
        } else {
            MessageUtil::setMessage("An error occurred while sending the reset link. Please try again.");
        }
        LocationUtils::redirectInternal("login");

    } catch (Exception $e) {
        MessageUtil::setMessage("An error occurred: " . $e->getMessage());
        LocationUtils::redirectInternal("forgot-password");
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}