<?php

use App\Repositories\WhatsappAccountRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new WhatsappAccountRepository();

    // Cambiar cuenta activa si viene parámetro
    if (isset($_GET["account_id"])) {
        $id = (int) $_GET["account_id"];
        $repo->setActive($id);
        LocationUtils::redirectInternal("panel/whatsapp");
    }

    $accounts = $repo->getAll();
    $active = $repo->getActive();
    $selectedId = $active ? $active->id : 1;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "accounts" => $accounts,
        "selected_id" => $selectedId
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
