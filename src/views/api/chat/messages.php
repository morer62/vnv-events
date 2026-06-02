<?php

use App\Repositories\ChatMessageRepository;
use App\Repositories\ChatThreadRepository;
use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $user = ApiAuthService::getAuthenticatedUser();
    $threadId = $_GET["thread"] ?? null;

    if (!$user || !$threadId) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Invalid thread ID or user not logged in."
        ]);
    }

    $threadRepo = new ChatThreadRepository();
    $thread = $threadRepo->getOne(['id' => (int)$threadId]);
    if (!$thread || ((int)$thread->id_user_1 !== (int)$user->getId() && (int)$thread->id_user_2 !== (int)$user->getId())) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Thread not found or not allowed."
        ], 403);
    }

    $repo = new ChatMessageRepository();
    $messages = $repo->getMessagesForThread((int)$threadId);
    $repo->markAsRead((int)$threadId, (int)$user->getId());

    return JsonResponse::createResponse([
        "success" => true,
        "thread_id" => (int)$threadId,
        "data" => $messages
    ]);
});

$router->run();
