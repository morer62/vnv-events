<?php

use App\Repositories\ChatMessageRepository;
use App\Services\LoginService;
use App\Utils\JsonResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $threadId = $_GET["thread"] ?? null;

    if (!$user || !$threadId) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Invalid thread ID or user not logged in."
        ]);
    }

    $repo = new ChatMessageRepository();
    $messages = $repo->getMessagesForThread((int)$threadId);

    return JsonResponse::createResponse([
        "success" => true,
        "data" => $messages
    ]);
});

$router->run();
