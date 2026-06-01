<?php

use App\Repositories\EventRequestRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $requestRepo = new EventRequestRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'user' => $user,
        'eventRequests' => $requestRepo->latestForOwner((int)$user->getOwner(), 6, false),
        'eventRequestsCount' => $requestRepo->countForOwner((int)$user->getOwner(), false),
        'eventRequestsArchivedCount' => $requestRepo->countForOwner((int)$user->getOwner(), true),
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $action = $_POST['action'] ?? '';

    if ($action === 'archive_event_request') {
        $id = (int)($_POST['event_request_id'] ?? 0);
        if ($id > 0) {
            $requestRepo = new EventRequestRepository();
            $archived = $requestRepo->archiveForOwner($id, (int)$user->getOwner());
            MessageUtil::setMessage($archived ? 'Event request archived.' : 'Could not archive this request.');
        }

        LocationUtils::redirectInternal('panel/home');
        return;
    }

    LocationUtils::redirectInternal('panel/home');
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
