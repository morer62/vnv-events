<?php

use App\Repositories\WhatsappRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new WhatsappRepository();
    $clients = $repo->getAllClients();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "clients" => $clients
    ]);
});

$router->post(function () {
    if (isset($_POST["delete"])) {
        $id = (int) $_POST["delete"];
        if ($id) {
            $repo = new WhatsappRepository();
            $repo->archiveClient($id);
            MessageUtil::setMessage("Contact deleted.");
        }
        LocationUtils::reload();
    }

    if (!isset($_POST["save"])) {
        LocationUtils::reload();
    }

    $id = (int) ($_POST["save"] ?? 0);
    $name = trim($_POST["name_$id"] ?? '');

    if (!$id || $name === "") {
        MessageUtil::setMessage("Both ID and name are required.");
        LocationUtils::reload();
    }

    $repo = new WhatsappRepository();
    $repo->updateClientName($id, $name);

    MessageUtil::setMessage("Contact updated.");
    LocationUtils::reload();
});

$router->run();

