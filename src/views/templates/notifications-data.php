<?php
use App\Repositories\NotificationsRepository;
use App\Services\LoginService;

$notificationsRepo = new NotificationsRepository();
$session = LoginService::getSession();
$userId = $session->getId();

$notifications = $notificationsRepo->getByUser($userId);
$notifications_count = $notificationsRepo->getUnreadCount($userId);

// Solo mostrar las primeras 5 notificaciones no leídas
$unreadNotifications = array_filter($notifications, function($notification) {
    return $notification->leido == 0;
});

$notifications = array_slice($unreadNotifications, 0, 5);
$notifications_count = count($notifications);
