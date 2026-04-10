<?php

use App\Repositories\StoreCartsRepository;
use App\Repositories\StoreCartItemsRepository;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $cartsRepo = new StoreCartsRepository();
    $itemsRepo = new StoreCartItemsRepository();
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();

    $carts = $cartsRepo->getAbandonedByOwner($ownerId, 200);

    foreach ($carts as &$cart) {
        $cart->items = $itemsRepo->getDetailedByCart((int)$cart->id);
    }
    unset($cart);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'carts' => $carts,
    ]);
});

$router->run();
