<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\NotificationsRepository;
use App\Services\LoginService;
use App\Utils\Response;

$router = new Router();

$router->get(function () {
    $notificationsRepo = new NotificationsRepository();
    $session = LoginService::getSession();
    $userId = $session->getId();

    // Siempre obtener todas las notificaciones del usuario para esta página
    $notifications = $notificationsRepo->getByUser($userId);
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "notifications" => $notifications
    ]);
});

// POST endpoint moved to separate mark-read.php file

try {
    $router->run();
} catch (Exception $e) {
    error_log("ERROR en notifications index.php: " . $e->getMessage());
    echo $e->getMessage();
}
