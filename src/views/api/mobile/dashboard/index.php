<?php

use App\Repositories\Connection;
use App\Repositories\EventRequestRepository;
use App\Services\ApiAuthService;
use App\Services\LoyaltyRewardsService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();
$router = new Router();

function mobileDashboardScalar(Connection $db, string $sql, array $bindings): int
{
    try {
        $db->query($sql);
        foreach ($bindings as $name => $value) $db->bind($name, $value);
        return (int)($db->fetchOne()->total ?? 0);
    } catch (Throwable $e) {
        error_log('[Mobile Dashboard] ' . $e->getMessage());
        return 0;
    }
}

$router->get(function () {
    $user = ApiAuthService::getAuthenticatedUser();
    if (!$user) return JsonResponse::createResponse(['success'=>false,'message'=>'Unauthorized'], 401);

    $level=(int)$user->getLevel(); $userId=(int)$user->getId(); $ownerId=(int)$user->getOwner();
    $db=new Connection(); $metrics=[]; $alerts=[];

    if ($level===1) {
        $requests=new EventRequestRepository();
        $metrics=[
            'pending_orders'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM orders WHERE id_owner=:owner AND is_archived=0 AND (status_workflow IS NULL OR status_workflow<>'INVOICE_PAID')",[':owner'=>$ownerId]),
            'upcoming_events'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM orders WHERE id_owner=:owner AND is_archived=0 AND event_date>=CURDATE()",[':owner'=>$ownerId]),
            'pending_deliveries'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM store_orders WHERE id_owner=:owner AND fulfillment_method='DELIVERY' AND payment_status='PAID' AND status NOT IN ('DELIVERED','COMPLETED','CANCELLED','CLOSED')",[':owner'=>$ownerId]),
            'unread_requests'=>$requests->countUnreadForOwner($ownerId),
            'open_requests'=>$requests->countForOwner($ownerId,false),
        ];
        try { $balance=(new LoyaltyRewardsService())->settings($ownerId,'vnvevents'); $metrics['reward_percent']=(float)$balance->reward_percent; $metrics['point_value']=(float)$balance->point_value; } catch(Throwable $e) {}
        if($metrics['unread_requests']>0)$alerts[]=['label'=>'New Requests','value'=>$metrics['unread_requests'],'route'=>'panel/home#event-requests'];
    } elseif ($level===4) {
        $metrics=[
            'assigned_orders'=>mobileDashboardScalar($db,"SELECT COUNT(DISTINCT o.id) total FROM orders o LEFT JOIN orders_suborders os ON os.id_order=o.id LEFT JOIN orders_suborders_staff oss ON oss.id_suborder=os.id WHERE o.id_owner=:owner AND o.event_date>=CURDATE() AND (o.main_manager_id=:user OR oss.id_user=:user)",[':owner'=>$ownerId,':user'=>$userId]),
            'upcoming_events'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM orders WHERE id_owner=:owner AND main_manager_id=:user AND event_date>=CURDATE()",[':owner'=>$ownerId,':user'=>$userId]),
        ];
    } else {
        $metrics=[
            'orders'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM orders WHERE id_client=:user AND is_archived=0",[':user'=>$userId]),
            'upcoming_events'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM orders WHERE id_client=:user AND is_archived=0 AND event_date>=CURDATE()",[':user'=>$userId]),
            'store_orders'=>mobileDashboardScalar($db,"SELECT COUNT(*) total FROM store_orders WHERE id_user=:user",[':user'=>$userId]),
        ];
        try { $loyalty=new LoyaltyRewardsService();$rewards=$loyalty->balance($ownerId,$userId);$settings=$loyalty->settings($ownerId); $metrics['available_points']=(float)$rewards['available_points']; $metrics['available_value']=(float)$rewards['available_value'];$metrics['processing_points']=(float)$rewards['pending_points'];$metrics['processing_value']=(float)$rewards['pending_value'];$metrics['reward_release_hours']=(int)($settings->release_hours??48); } catch(Throwable $e) {}
    }

    return JsonResponse::createResponse(['success'=>true,'data'=>['level'=>$level,'metrics'=>$metrics,'alerts'=>$alerts,'generated_at'=>date(DATE_ATOM)]]);
});
$router->run();
