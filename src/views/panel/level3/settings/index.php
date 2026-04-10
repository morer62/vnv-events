<?php

use App\Repositories\UserRepository;
use App\Services\HashService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'user' => $user
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $userRepository = new UserRepository();

    $name = $_POST["name"];
    $lastname = $_POST["lastname"];
    $email = $_POST["email"];
    $password = $_POST["password"] ?? '';
    $passwordRepeat = $_POST["password_repeat"] ?? '';

    if ($password && $password !== $passwordRepeat) {
        MessageUtil::setMessage("Passwords do not match.");
        LocationUtils::redirectInternal("panel/settings");
    }

    $updateData = [
        'name' => $name,
        'lastname' => $lastname,
        'email' => $email,
    ];

    if (!empty($password)) {
        $updateData['password'] = HashService::hashPassword($password);
    }

    $userRepository->updateData($user->getId(), $updateData);

    // cerrar sesión y forzar re-login
    LoginService::logout();
    MessageUtil::setMessage("Profile updated successfully. Please log in again to continue.");
    LocationUtils::redirectInternal("login");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
