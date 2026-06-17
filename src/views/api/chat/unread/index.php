<?php

use App\Repositories\ChatThreadRepository;
use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $user = ApiAuthService::getAuthenticatedUser();
    if (!$user) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "User not logged in."
        ], 401);
    }

    $baseRoute = ((int)$user->getLevel() === 5) ? 'panel/chat' : 'panel/planner-hub/team/chat';
    $threads = (new ChatThreadRepository())->getUnreadSummariesForUser((int)$user->getId());

    $data = array_map(function ($thread) use ($baseRoute) {
        return [
            'id' => (int)$thread->id,
            'partner_id' => (int)$thread->partner_id,
            'partner_name' => $thread->partner_name ?? '',
            'partner_email' => $thread->partner_email ?? '',
            'unread_count' => (int)$thread->unread_count,
            'last_unread_message' => $thread->last_unread_message ?? '',
            'last_unread_at' => $thread->last_unread_at ?? null,
            'link' => LocationUtils::assetFor($baseRoute . '?thread=' . (int)$thread->id),
        ];
    }, $threads);

    return JsonResponse::createResponse([
        "success" => true,
        "count" => count($data),
        "data" => $data
    ]);
});

$router->run();