<?php


use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\TwilioService;
use App\Utils\FormatPhone;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $user = LoginService::getSession();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user,
    ]);
});

$router->post(function () {
    $twilioService = new TwilioService();
    $userRepository = new UserRepository();

    $user = LoginService::getSession();
    $phone = FormatPhone::formatPhone($_POST["phone"]);
    $code = rand(1000, 9999);
    $message = "Your verification code is: $code";

    $userRepository->update(
        ["phone_code" => "$code"],
        ["id" => $user->getId()]
    );

    $user->setPhoneCode($code);
    $twilioService->sendMessage($phone, $message);

    LoginService::setSession($user);
    LocationUtils::redirectInternal("panel/phone/code");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
