<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Utils\UserContext;
use App\Repositories\StripeAccountsRepository;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $context = UserContext::get();

    $stripeRepo = new StripeAccountsRepository();
    $stripeAccount = $stripeRepo->getByUser($user->getId());

    $stripeConnected = $stripeAccount && ($stripeAccount->is_verified ?? false);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "stripe_connected" => $stripeConnected
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
