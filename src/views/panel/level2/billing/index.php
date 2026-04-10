<?php

use App\Services\LoginService;
use App\Repositories\UserBillingInfoRepository;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $repo = new UserBillingInfoRepository();

    $billing = $repo->getByUserId($user->getId());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "billing" => $billing
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $repo = new UserBillingInfoRepository();

    $data = [
        "billing_address_1" => $_POST["billing_address_1"] ?? "",
        "billing_city" => $_POST["billing_city"] ?? "",
        "billing_state" => $_POST["billing_state"] ?? "",
        "billing_zip" => $_POST["billing_zip"] ?? ""
    ];

    $repo->upsert($user->getId(), $data);

    MessageUtil::setMessage("Address Updated");
    LocationUtils::redirectInternal("panel/cards");
});

$router->run();
