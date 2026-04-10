<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Repositories\WhatsappRepository;

Cors::handle();

$router = new Router();

$router->get(function () {
    $client_id = $_GET["client_id"] ?? null;
    $channel = $_GET["channel"] ?? 'whatsapp'; // 👈 Nuevo: permite seleccionar canal

    if (!$client_id) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "client_id is required"
        ]);
    }

    $repo = new WhatsappRepository();

    // 🟡 Marcar mensajes inbound como leídos
    $repo->markMessagesAsRead((int)$client_id);

    $messages = $repo->getMessagesByClient((int)$client_id, $channel); // 👈 pasamos el canal

    return JsonResponse::createResponse([
        "success" => true,
        "data" => $messages
    ]);
});

$router->run();
