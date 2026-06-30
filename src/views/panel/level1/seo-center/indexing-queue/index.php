<?php

use App\Services\LoginService;
use App\Services\SeoIndexingQueueService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    require_seo_indexing_queue_access();

    $service = new SeoIndexingQueueService();
    $synced = $service->syncPublishedUrls();
    $status = strtolower(trim((string)($_GET['status'] ?? 'pending')));
    $dashboard = $service->dashboard($status);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        ...$dashboard,
        'synced_count' => $synced,
    ]);
});

$router->post(function () {
    require_seo_indexing_queue_access();

    $service = new SeoIndexingQueueService();
    $user = LoginService::getSession();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'sync') {
        $synced = $service->syncPublishedUrls();
        MessageUtil::setMessage($synced . ' public URLs added to the indexing queue.', 'Success', 'success');
        LocationUtils::redirectInternal('panel/seo-center/indexing-queue');
        return;
    }

    if ($id <= 0 || !in_array($action, ['mark_indexed', 'mark_pending'], true)) {
        MessageUtil::setMessage('Invalid indexing queue action.', 'Error', 'error');
        LocationUtils::reload();
        return;
    }

    $ok = $action === 'mark_indexed'
        ? $service->markIndexed($id, (int)$user->getId())
        : $service->markPending($id);

    MessageUtil::setMessage(
        $ok ? 'Indexing queue updated.' : 'The selected URL could not be updated.',
        $ok ? 'Success' : 'Error',
        $ok ? 'success' : 'error'
    );

    $status = $action === 'mark_indexed' ? 'pending' : 'indexed';
    LocationUtils::redirectInternal('panel/seo-center/indexing-queue?status=' . $status);
});

$router->run();

function require_seo_indexing_queue_access(): void
{
    $user = LoginService::getSession();
    if ((int)$user->getLevel() === 1) {
        return;
    }

    MessageUtil::setMessage('This SEO indexing queue is reserved for Level 1 administrators.', 'Error', 'error');
    LocationUtils::redirectInternal('panel/home');
}
