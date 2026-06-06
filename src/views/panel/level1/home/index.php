<?php

use App\Repositories\EventRequestRepository;
use App\Services\LoginService;
use App\Services\OphyraGrowthHubClient;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $requestRepo = new EventRequestRepository();
    $growthHubClient = new OphyraGrowthHubClient();
    $growthHubSummary = [
        'configured' => $growthHubClient->isConfigured(),
        'site_key' => $growthHubClient->siteKey(),
        'blog_count' => 0,
        'location_count' => 0,
        'page_count' => 0,
    ];

    if ($growthHubSummary['configured']) {
        $growthHubSummary['blog_count'] = count($growthHubClient->contentList('blog', 1000));
        $growthHubSummary['location_count'] = count($growthHubClient->contentList('location', 1000));
        $growthHubSummary['page_count'] = count(array_merge(
            $growthHubClient->contentList('page', 1000),
            $growthHubClient->contentList('landing', 1000),
            $growthHubClient->contentList('custom', 1000)
        ));
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'user' => $user,
        'eventRequests' => $requestRepo->latestForOwner((int)$user->getOwner(), 6, false),
        'eventRequestsCount' => $requestRepo->countForOwner((int)$user->getOwner(), false),
        'eventRequestsArchivedCount' => $requestRepo->countForOwner((int)$user->getOwner(), true),
        'growthHubSummary' => $growthHubSummary,
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
