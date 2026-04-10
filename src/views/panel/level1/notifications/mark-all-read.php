<?php

use App\Repositories\NotificationsRepository;
use App\Services\LoginService;
use App\Utils\Response;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$notificationsRepo = new NotificationsRepository();
$session = LoginService::getSession();
$userId = $session->getId();

$unreadCount = $notificationsRepo->getUnreadCount($userId);
$success = $notificationsRepo->markAllAsRead($userId);
$unreadCountAfter = $notificationsRepo->getUnreadCount($userId);

if ($success) {
    echo json_encode([
        'success' => true,
        'message' => 'All notifications marked as read',
        'unread_before' => $unreadCount,
        'unread_after' => $unreadCountAfter
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to mark notifications as read',
        'unread_before' => $unreadCount,
        'unread_after' => $unreadCountAfter
    ]);
}
