<?php

use App\Repositories\StoreOrdersRepository;
use App\Repositories\StoreSubscriptionsRepository;
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
    $subscriptionsRepo = new StoreSubscriptionsRepository();
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

    // ✅ Subscription
    $subscription = null;
    if (($order->pricing_mode ?? '') === StoreOrdersRepository::PRICING_SUBSCRIPTION) {
        $subscription = $subscriptionsRepo->getActiveByEmail($order->guest_email);
    }

    // ✅ Usuario existente
    $existingUser = null;
    if (!empty($order->guest_email)) {
        $existingUser = $userRepo->getOne(['email' => $order->guest_email]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "subscription" => $subscription,
        "existingUser" => $existingUser
    ]);
});

$router->run();