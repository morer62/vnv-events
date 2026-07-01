<?php

use App\Repositories\NotificationsRepository;
use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

function mobileNotificationPayload(object $notification): array
{
    $message = (string)($notification->mensaje ?? '');
    $parts = preg_split("/\r\n|\n|\r/", $message, 2);
    $title = trim((string)($parts[0] ?? 'Notification'));
    $body = trim((string)($parts[1] ?? ''));

    if ($body === '') {
        $body = $message;
    }

    $link = (string)($notification->link ?? '');

    return [
        'id' => (int)$notification->id,
        'title' => $title !== '' ? $title : 'Notification',
        'body' => $body,
        'message' => $message,
        'link' => $link,
        'is_read' => (int)($notification->leido ?? 0) === 1,
        'created_at' => (string)($notification->timestamp ?? ''),
        'type' => str_starts_with($link, 'mobile-app-broadcast://') ? 'mobile_app_broadcast' : 'system',
    ];
}

$router->get(function () {
    $user = ApiAuthService::getAuthenticatedUser();
    if (!$user) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $repo = new NotificationsRepository();
    $rows = $repo->getByUser((int)$user->getId());

    return JsonResponse::createResponse([
        'success' => true,
        'data' => array_map('mobileNotificationPayload', $rows),
    ]);
});

$router->post(function () {
    $body = ApiAuthService::bodyFromJsonOrPost();
    $user = ApiAuthService::getAuthenticatedUser(null, $body);
    if (!$user) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $notificationId = (int)($body['notification_id'] ?? $body['id'] ?? 0);
    if ($notificationId <= 0) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'notification_id is required',
        ], 422);
    }

    $repo = new NotificationsRepository();
    $notification = $repo->getByUserAndId((int)$user->getId(), $notificationId);
    if (!$notification) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Notification not found',
        ], 404);
    }

    $repo->markAsRead($notificationId);

    return JsonResponse::createResponse([
        'success' => true,
        'data' => mobileNotificationPayload($notification),
    ]);
});

$router->run();
