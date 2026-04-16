<?php

use App\Repositories\StoreOrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $token = trim($_GET['token'] ?? '');

    if ($token === '') {
        MessageUtil::setMessage("Order token not found.");
        LocationUtils::redirectInternal("/store");
    }

    $ordersRepo = new StoreOrdersRepository();
    $userRepo = new UserRepository();

    $order = $ordersRepo->getByPublicToken($token);

    if (!$order) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::redirectInternal("/store");
    }

    $order = $ordersRepo->getFullOrderDetails((int)$order->id);

    if (!$order) {
        MessageUtil::setMessage("Order not found.");
        LocationUtils::redirectInternal("/store");
    }

    $existingUser = null;
    if (!empty($order->guest_email)) {
        $existingUser = $userRepo->getOne(['email' => $order->guest_email]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "existingUser" => $existingUser
    ]);
});

$router->run();