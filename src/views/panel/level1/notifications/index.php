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

    $notifications = $notificationsRepo->getUnreadByUser($userId);
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "notifications" => $notifications
    ]);
});

// POST endpoint moved to separate mark-read.php file

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
