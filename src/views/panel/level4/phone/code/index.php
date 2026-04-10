<?php

use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig");
});

$router->post(function () {
    $userRepository = new UserRepository();

    $code = $_POST["code"];
    $user = LoginService::getSession();

    if ($code != $user->getPhoneCode()) {
        MessageUtil::setMessage("Code is incorrect");
        LocationUtils::redirectInternal("panel/phone/code");
    }

    $userRepository->update(
        ["phone_validation" => 1],
        ["id" => $user->getId()]
    );

    $user->setPhoneValidation(1);
    LoginService::setSession($user);

    LocationUtils::redirectInternal("panel/home");
});



try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}