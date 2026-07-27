<?php

use App\Repositories\EventRequestRepository;
use App\Repositories\Connection;
use App\Repositories\AiAgentsRepository;
use App\Services\LoginService;
use App\Services\OphyraGrowthHubClient;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function level1HomeOrderSummary(int $ownerId): array
{
    $db = new Connection();
    $summary = [
        'pending_orders' => 0,
        'upcoming_events' => 0,
        'pending_contracts' => 0,
    ];

    try {
        $db->query("SELECT COUNT(*) AS total FROM orders WHERE id_owner = :owner AND is_archived = 0 AND (status_workflow IS NULL OR status_workflow <> 'INVOICE_PAID')");
        $db->bind(':owner', $ownerId);
        $summary['pending_orders'] = (int)($db->fetchOne()->total ?? 0);

        $db->query("SELECT COUNT(*) AS total FROM orders WHERE id_owner = :owner AND is_archived = 0 AND event_date >= CURDATE()");
        $db->bind(':owner', $ownerId);
        $summary['upcoming_events'] = (int)($db->fetchOne()->total ?? 0);

        $db->query("
            SELECT COUNT(*) AS total
            FROM orders o
            WHERE o.id_owner = :owner
              AND o.is_archived = 0
              AND o.event_date >= CURDATE()
              AND NOT EXISTS (
                  SELECT 1
                  FROM document_logs dl
                  WHERE dl.id_order = o.id
                    AND dl.doc_type = 'contract_signed'
              )
        ");
        $db->bind(':owner', $ownerId);
        $summary['pending_contracts'] = (int)($db->fetchOne()->total ?? 0);
    } catch (Throwable $e) {
        error_log('[Level1 Home] Summary failed: ' . $e->getMessage());
    }

    return $summary;
}

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
    $agentApprovals=[];
    try{
        $agentRepo=new AiAgentsRepository();
        if($agentRepo->storageReady())$agentApprovals=array_values(array_filter($agentRepo->pendingApprovals((int)$user->getOwner()),fn($item)=>$item->status==='PENDING'));
    }catch(Throwable $e){error_log('[Level1 Home] Agent approvals failed: '.$e->getMessage());}

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'user' => $user,
        'orderSummary' => level1HomeOrderSummary((int)$user->getOwner()),
        'eventRequests' => $requestRepo->latestForOwner((int)$user->getOwner(), 6, false),
        'eventRequestsCount' => $requestRepo->countForOwner((int)$user->getOwner(), false),
        'eventRequestsArchivedCount' => $requestRepo->countForOwner((int)$user->getOwner(), true),
        'growthHubSummary' => $growthHubSummary,
        'agentApprovals' => $agentApprovals,
        'agentApprovalsCount' => count($agentApprovals),
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
