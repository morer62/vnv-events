<?php

use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\HashService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $token = $_GET['token'] ?? '';

    $passwordRepo = new PasswordResetRepository();

    if (!$token || !$passwordRepo->isValid($token)) {
        MessageUtil::setMessage("Invalid or expired token.");
        LocationUtils::redirectInternal("login");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", ['token' => $token]);
});

$router->post(function () {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $passwordRepeat = $_POST['password_repeat'] ?? '';

    $passwordRepo = new PasswordResetRepository();
    $userRepo = new UserRepository();

    if (!$token || !$passwordRepo->isValid($token)) {
        MessageUtil::setMessage("Invalid or expired token.");
        LocationUtils::redirectInternal("login");
    }

    if ($password !== $passwordRepeat) {
        MessageUtil::setMessage("Passwords do not match.");
        LocationUtils::redirectInternal("reset-password?token=" . urlencode($token));
    }

    $result = $passwordRepo->getOne(criteriaVals: [ "token" => $token], columns: ["email"] );

    if (!$result?->email) {
        MessageUtil::setMessage("User email not found.");
        LocationUtils::redirectInternal("login");
    }

    $hashedPassword = HashService::hashPassword($password);
    $userRepo->updateByEmail($result->email, ['password' => $hashedPassword]);

    $passwordRepo->delete([ "token" => $token ]);

    MessageUtil::setMessage("Password successfully updated. You can now log in.");
    LocationUtils::redirectInternal("login");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
