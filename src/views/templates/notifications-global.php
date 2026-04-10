<?php
// Este archivo se incluye globalmente para todas las páginas de administrador
// y establece las variables de notificaciones que se usarán en Twig

use App\Repositories\NotificationsRepository;
use App\Services\LoginService;

// Solo ejecutar si no se ha ejecutado antes
if (!isset($GLOBALS['notifications_loaded'])) {
    $GLOBALS['notifications_loaded'] = true;
    
    try {
        $notificationsRepo = new NotificationsRepository();
        $session = LoginService::getSession();
        $userId = $session->getId();

        $allNotifications = $notificationsRepo->getByUser($userId);
        $notifications_count = $notificationsRepo->getUnreadCount($userId);

        // Solo mostrar las primeras 5 notificaciones no leídas para el header
        $unreadNotifications = array_filter($allNotifications, function($notification) {
            return $notification->leido == 0;
        });

        $headerNotifications = array_slice($unreadNotifications, 0, 5);
        $headerNotificationsCount = count($headerNotifications);

        // Establecer las variables globales para Twig
        $GLOBALS['notifications'] = $headerNotifications;
        $GLOBALS['notifications_count'] = $headerNotificationsCount;
        $GLOBALS['all_notifications'] = $allNotifications; // Todas las notificaciones
        
    } catch (Exception $e) {
        error_log("ERROR en notifications-global.php: " . $e->getMessage());
        $GLOBALS['notifications'] = [];
        $GLOBALS['notifications_count'] = 0;
        $GLOBALS['all_notifications'] = [];
    }
}
