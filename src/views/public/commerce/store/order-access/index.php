<?php

use App\Repositories\StoreOrdersRepository;
use App\Repositories\UserRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $token = trim($_GET['token'] ?? '');

    if ($token === '') {
        http_response_code(404);
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "order" => null,
            "existingUser" => null,
            "errorMessage" => "Order token not found."
        ]);
    }

    $ordersRepo = new StoreOrdersRepository();
    $userRepo = new UserRepository();

    $order = $ordersRepo->getByPublicToken($token);

    if (!$order) {
        http_response_code(404);
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "order" => null,
            "existingUser" => null,
            "errorMessage" => "Order not found."
        ]);
    }

    $order = $ordersRepo->getFullOrderDetails((int)$order->id);

    if (!$order) {
        http_response_code(404);
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "order" => null,
            "existingUser" => null,
            "errorMessage" => "Order not found."
        ]);
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
