<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Repositories\WhatsappRepository;

Cors::handle();

$router = new Router();

$router->get(function () {
    $repo = new WhatsappRepository();
    //$clients = $repo->getAllClients();
    $clients = $repo->getClientsSortedByActivity();


    return JsonResponse::createResponse([
        'success' => true,
        'data' => $clients
    ]);
});

$router->run();
