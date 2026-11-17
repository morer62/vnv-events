<?php

use App\Repositories\EventRequestRepository;
use App\Repositories\Connection;
use App\Repositories\AiAgentsRepository;
use App\Services\LoginService;
use App\Services\OphyraGrowthHubClient;
use App\Services\LoyaltyRewardsService;
use App\Services\AutomationCenterService;
use App\Services\GourmetExpressService;
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
        'pending_delivery_orders' => 0,
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

        $db->query("SELECT COUNT(*) AS total FROM store_orders WHERE id_owner=:owner AND fulfillment_method='DELIVERY' AND payment_status='PAID' AND status NOT IN ('DELIVERED','COMPLETED','CANCELLED','CLOSED')");
        $db->bind(':owner', $ownerId);
        $summary['pending_delivery_orders'] = (int)($db->fetchOne()->total ?? 0);
    } catch (Throwable $e) {
        error_log('[Level1 Home] Summary failed: ' . $e->getMessage());
    }

    return $summary;
}

function level1HomeRewardsSummary(int $ownerId): array
{
    $summary = [
        'reward_percent' => 0.0,
        'point_value' => 0.0,
        'clients_with_balance' => 0,
        'available_points' => 0.0,
        'pending_points' => 0.0,
    ];
    try {
        $settings = (new LoyaltyRewardsService())->settings($ownerId, 'vnvevents');
        $summary['reward_percent'] = (float)$settings->reward_percent;
        $summary['point_value'] = (float)$settings->point_value;
        $db = new Connection();
        $db->query("SELECT COUNT(*) clients_with_balance,COALESCE(SUM(available_points),0) available_points,COALESCE(SUM(pending_points),0) pending_points FROM (SELECT id_user,SUM(CASE WHEN status IN ('AVAILABLE','REDEEMED') THEN points ELSE 0 END) available_points,SUM(CASE WHEN status='PENDING' THEN points ELSE 0 END) pending_points FROM loyalty_transactions WHERE id_owner=:owner AND site_key='vnvevents' GROUP BY id_user HAVING available_points>0 OR pending_points>0) balances");
        $db->bind(':owner', $ownerId);
        $row = $db->fetchOne();
        $summary['clients_with_balance'] = (int)($row->clients_with_balance ?? 0);
        $summary['available_points'] = (float)($row->available_points ?? 0);
        $summary['pending_points'] = (float)($row->pending_points ?? 0);
    } catch (Throwable $e) {
        error_log('[Level1 Home] Rewards summary failed: ' . $e->getMessage());
    }
    return $summary;
}

function level1HomeRequestSourceLabel(string $source): string
{
    $labels = [
        'public_home_modal' => 'Website home · Event request form',
        'gourmet_short_form' => 'VNV Gourmet landing · Quick quote form',
        'gourmet_comparison_landing' => 'Catering stations comparison page',
        'corporate_events' => 'Corporate Events page',
        'event_budget_calculator' => 'Event budget calculator',
        'podcasts_header_request' => 'Podcasting page',
    ];

    if (isset($labels[$source])) {
        return $labels[$source];
    }

    if (str_starts_with($source, 'gourmet_landing_')) {
        $service = substr($source, strlen('gourmet_landing_'));
        return 'VNV Gourmet · ' . ucwords(str_replace(['_', '-'], ' ', $service)) . ' landing';
    }

    $readable = trim(ucwords(str_replace(['_', '-'], ' ', $source)));
    return $readable !== '' ? $readable : 'Website · Source not recorded';
}

function level1HomeRequestDate(?string $value, string $format, string $fallback = 'Not provided'): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable $e) {
        return $value;
    }
}

function level1HomeRequestView(object $request): array
{
    $services = json_decode((string)($request->selected_services ?? ''), true);
    if (!is_array($services)) {
        $services = preg_split('/[,;\n]+/', (string)($request->selected_services ?? '')) ?: [];
    }
    $services = array_values(array_filter(array_map(static function ($service): string {
        if (is_array($service)) {
            return trim((string)($service['name'] ?? $service['title'] ?? ''));
        }
        return trim((string)$service);
    }, $services)));

    $rawDetails = trim((string)($request->details ?? ''));
    $decodedDetails = json_decode($rawDetails, true);
    $metadata = is_array($decodedDetails) ? $decodedDetails : [];
    $detailsText = is_array($decodedDetails)
        ? trim((string)($decodedDetails['notes'] ?? $decodedDetails['details'] ?? ''))
        : $rawDetails;

    $source = trim((string)($request->form_source ?? ''));
    $landingUrl = trim((string)($metadata['landing_url'] ?? ''));
    if ($landingUrl === '' || !preg_match('#^/[a-zA-Z0-9/_?&=.%+-]*$#', $landingUrl)) {
        $landingUrl = '';
    }

    $extra = [];
    $addExtra = static function (string $label, mixed $value) use (&$extra): void {
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }
        if ($value === null || trim((string)$value) === '') {
            return;
        }
        $extra[] = ['label' => $label, 'value' => trim((string)$value)];
    };

    $addExtra('Reference code', $metadata['ref_code'] ?? null);
    $addExtra('Requested service', isset($metadata['service']) ? ucwords(str_replace(['_', '-'], ' ', (string)$metadata['service'])) : null);
    $addExtra('Guest range', isset($metadata['guest_bucket']) ? str_replace('-', '–', (string)$metadata['guest_bucket']) : null);
    $addExtra('ZIP code', $metadata['zip'] ?? null);
    $addExtra('County', $metadata['zip_classification']['county'] ?? null);
    $addExtra('Lead review', isset($metadata['qualification_status']) ? ucwords(strtolower(str_replace('_', ' ', (string)$metadata['qualification_status']))) : null);
    $addExtra('Gourmet To Go alternative', !empty($metadata['gourmet_to_go_alternative']) ? 'Recommended' : null);

    $attribution = isset($metadata['attribution']) && is_array($metadata['attribution']) ? $metadata['attribution'] : [];
    foreach ([
        'gclid' => 'Google click ID',
        'gbraid' => 'Google GBRAID',
        'wbraid' => 'Google WBRAID',
        'utm_source' => 'Campaign source',
        'utm_medium' => 'Campaign medium',
        'utm_campaign' => 'Campaign name',
        'utm_term' => 'Campaign term',
        'utm_content' => 'Campaign content',
    ] as $key => $label) {
        $addExtra($label, $attribution[$key] ?? null);
    }

    $guestCount = (int)($request->guest_count ?? 0);
    $status = strtoupper(trim((string)($request->status ?? 'NEW')));
    $requestEmail = trim((string)($request->email ?? ''));
    $requestName = trim((string)($request->full_name ?? ''));
    $isTest = !empty($metadata['is_test'])
        || (bool)preg_match('/@(example\.)?(test|invalid)$/i', $requestEmail)
        || (bool)preg_match('/\b(browser\s+qa|qa\s+.+test)\b/i', $requestName);

    return [
        'id' => (int)$request->id,
        'full_name' => $requestName ?: 'Name not provided',
        'email' => $requestEmail,
        'phone' => trim((string)($request->phone ?? '')),
        'address' => trim((string)($request->event_address ?? '')) ?: 'Address not provided',
        'event_date' => level1HomeRequestDate($request->event_date ?? null, 'M j, Y'),
        'event_time' => level1HomeRequestDate($request->event_time ?? null, 'g:i A'),
        'received_at' => level1HomeRequestDate($request->created_at ?? null, 'M j, Y · g:i A'),
        'archived_at' => level1HomeRequestDate($request->archived_at ?? null, 'M j, Y · g:i A', ''),
        'guest_count' => $guestCount > 0 ? $guestCount : null,
        'guest_label' => $guestCount > 0 ? $guestCount . ' guests' : ((string)($metadata['guest_bucket'] ?? '') !== '' ? str_replace('-', '–', (string)$metadata['guest_bucket']) . ' guests' : 'Not provided'),
        'services' => $services,
        'details_text' => $detailsText,
        'source' => $source,
        'source_label' => level1HomeRequestSourceLabel($source),
        'source_url' => $landingUrl,
        'status' => $status,
        'status_label' => ucwords(strtolower(str_replace('_', ' ', $status))),
        'is_new' => $status === 'NEW',
        'is_test' => $isTest,
        'is_archived' => (bool)($request->is_archived ?? false),
        'extra' => $extra,
        'lead_intake_id' => (int)($request->lead_intake_id ?? 0),
        'converted_order_id' => (int)($request->converted_order_id ?? 0),
    ];
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

    $frogIsReviewer=false;$frogReviewCount=0;$frogState=['state'=>'sad','greeting'=>''];try{$automation=new AutomationCenterService((int)$user->getOwner());$frogIsReviewer=$automation->isReviewer((int)$user->getId())||(int)$user->getLevel()===1;$frogReviewCount=count($automation->pendingReview(100));$frogState=$automation->mascotState($frogReviewCount);}catch(Throwable $e){}
    $gourmetSettings=[];try{$gourmetSettings=(new GourmetExpressService())->settings((int)$user->getOwner(),'vnvevents');}catch(Throwable $e){error_log('[Level1 Home] Gourmet status failed: '.$e->getMessage());}
    $activeRequests = array_map('level1HomeRequestView', $requestRepo->latestForOwner((int)$user->getOwner(), 100, false));
    $archivedRequests = array_map('level1HomeRequestView', $requestRepo->latestForOwner((int)$user->getOwner(), 100, true));
    usort($activeRequests, static function (array $left, array $right): int {
        if ($left['is_test'] !== $right['is_test']) {
            return $left['is_test'] ? 1 : -1;
        }
        if ($left['is_new'] !== $right['is_new']) {
            return $left['is_new'] ? -1 : 1;
        }
        return $right['id'] <=> $left['id'];
    });
    $realUnreadRequests = count(array_filter($activeRequests, static fn (array $request): bool => $request['is_new'] && !$request['is_test']));
    $testRequests = count(array_filter($activeRequests, static fn (array $request): bool => $request['is_test']));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'user' => $user,
        'orderSummary' => level1HomeOrderSummary((int)$user->getOwner()),
        'rewardsSummary' => level1HomeRewardsSummary((int)$user->getOwner()),
        'eventRequests' => $activeRequests,
        'eventRequestsArchived' => $archivedRequests,
        'eventRequestsCount' => $requestRepo->countForOwner((int)$user->getOwner(), false),
        'eventRequestsUnreadCount' => $realUnreadRequests,
        'eventRequestsTestCount' => $testRequests,
        'eventRequestsArchivedCount' => $requestRepo->countForOwner((int)$user->getOwner(), true),
        'growthHubSummary' => $growthHubSummary,
        'agentApprovals' => $agentApprovals,
        'agentApprovalsCount' => count($agentApprovals),
        'frogIsReviewer'=>$frogIsReviewer,'frogReviewCount'=>$frogReviewCount,'frogState'=>$frogState,
        'gourmetSettings'=>$gourmetSettings,
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

        LocationUtils::redirectInternal('panel/home#event-requests');
        return;
    }

    if ($action === 'restore_event_request') {
        $id = (int)($_POST['event_request_id'] ?? 0);
        if ($id > 0) {
            $requestRepo = new EventRequestRepository();
            $restored = $requestRepo->restoreForOwner($id, (int)$user->getOwner());
            MessageUtil::setMessage($restored ? 'Event request restored.' : 'Could not restore this request.');
        }

        LocationUtils::redirectInternal('panel/home#event-requests');
        return;
    }

    if ($action === 'read_event_request') {
        $id = (int)($_POST['event_request_id'] ?? 0);
        if ($id > 0) {
            (new EventRequestRepository())->markReadForOwner($id, (int)$user->getOwner());
        }

        LocationUtils::redirectInternal('panel/home#event-requests');
        return;
    }

    LocationUtils::redirectInternal('panel/home');
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
