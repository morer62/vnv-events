<?php

use App\Repositories\WhatsappRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $repo = new WhatsappRepository();
    $clients = $repo->getAllArchivedClients();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "clients" => $clients
    ]);
});

$router->post(function () {
    // 1. Delete
    if (isset($_POST["unarchive"])) {
        $id = (int) $_POST["unarchive"];
        if ($id) { 
            $repo = new WhatsappRepository();
            $repo->unarchiveClient ($id);
            MessageUtil::setMessage("Your contact is back.");
        }

      
 
     LocationUtils::reload();
    }

    // 2. Save
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
