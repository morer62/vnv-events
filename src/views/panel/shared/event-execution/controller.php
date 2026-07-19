<?php

use App\Services\EventExecutionService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$service = new EventExecutionService();

$resolveSpace = static function () use ($service) {
    $user = LoginService::getSession();
    $code = preg_replace('/\D/', '', (string)($_REQUEST['code'] ?? ''));
    $orderId = (int)($_REQUEST['order_id'] ?? 0);
    if ($orderId > 0 && in_array((int)$user->getLevel(), [1, 4, 5], true)) {
        $orders = new \App\Repositories\OrdersRepository();
        // This endpoint performs its own explicit level/client/team authorization.
        // Repository ownership scoping would hide a client's own order because the
        // order belongs to the Level 1 owner, not to the Level 5 session owner.
        $orderRow = $orders->getByIdWithoutOwnershipCheck($orderId);
        $order = $orderRow ? (object)$orderRow : null;
        if (!$order) return null;
        $allowed = (int)$user->getLevel() === 1
            || ((int)$user->getLevel() === 5 && (int)$order->id_client === (int)$user->getId());
        if ((int)$user->getLevel() === 4) {
            foreach ($orders->getOrdersByInvitation((int)$user->getId()) as $assigned) {
                if ((int)$assigned->id === $orderId && (int)$assigned->is_confirmed === 1) { $allowed = true; break; }
            }
        }
        if (!$allowed) return null;
        return $service->getOrCreateForOrder($orderId, (int)$user->getId(), (int)$order->id_owner);
    }
    return strlen($code) === 5 ? $service->findByCode($code) : null;
};

$router->get(callback: function () use ($service, $resolveSpace) {
    $user = LoginService::getSession();
    $space = $resolveSpace();
    if ($space && !$service->canOpen($space, $user)) $space = null;
    if ($space) $service->join($space, $user);
    if (($_GET['format'] ?? '') === 'state') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        if (!$space) {
            http_response_code(404);
            echo json_encode(['success'=>false,'error'=>'Event area not found.']);
            exit;
        }
        echo json_encode(['success'=>true,'version'=>$service->stateVersion((int)$space->id),'server_time'=>date(DATE_ATOM)]);
        exit;
    }
    $data = $space ? $service->dashboard($space) : ['karaoke'=>[], 'song_requests'=>[], 'photos'=>[]];
    $payment = $space ? $service->paymentOptions($space, $user) : ['provider'=>null, 'methods'=>[]];
    return TemplateResponse::render(dirname(__DIR__) . '/event-execution/index.twig', [
        'space'=>$space, 'karaoke'=>$data['karaoke'], 'songRequests'=>$data['song_requests'], 'photos'=>$data['photos'], 'photoFolders'=>$data['photo_folders']??[], 'members'=>$data['members']??[],
        'currentUserId'=>(int)$user->getId(), 'userLevel'=>(int)$user->getLevel(),
        'isClientOwner'=>$space && (int)$space->id_client === (int)$user->getId(),
        'isMusicManager'=>$space ? $service->isMusicManager((int)$space->id,$user) : false,
        'tipProvider'=>$payment['provider'], 'tipPaymentMethods'=>$payment['methods'],
        'stateVersion'=>$space ? $service->stateVersion((int)$space->id) : null,
    ]);
});

$router->post(callback: function () use ($service, $resolveSpace) {
    $user=LoginService::getSession(); $action=(string)($_POST['action']??'join'); $space=$resolveSpace();
    try {
        if (!$space) throw new RuntimeException('Event code not found.');
        if ($action==='join') $service->join($space,$user);
        elseif (!$service->canOpen($space,$user)) throw new RuntimeException('You do not have access to this event.');
        elseif ($action==='add_music') $service->addMusic((int)$space->id,$user,$_POST);
        elseif ($action==='delete_music') $service->deleteMusic((int)$space->id,(int)($_POST['request_id']??0),$user);
        elseif ($action==='update_music') $service->updateMusic((int)$space->id,(int)($_POST['request_id']??0),$user,$_POST);
        elseif ($action==='set_member_role') $service->setMemberRole((int)$space->id,(int)($_POST['member_id']??0),(string)($_POST['role']??''),$user);
        elseif ($action==='pay_tip') $service->payTip((int)$space->id,(int)($_POST['request_id']??0),(int)($_POST['saved_payment_method_id']??0),$user);
        elseif ($action==='add_photo') $service->addPhoto((int)$space->id,$user,$_FILES['photo']??[],(string)($_POST['caption']??''));
        elseif ($action==='delete_photo') $service->deletePhoto((int)$space->id,(int)($_POST['photo_id']??0),$user,(int)$space->id_client===(int)$user->getId());
        elseif ($action==='delete_all_photos') $service->deleteAllPhotos((int)$space->id,$user,(int)$space->id_client===(int)$user->getId());
        MessageUtil::setMessage('Event area updated.');
        LocationUtils::redirectInternal('panel/event-execution?code='.$space->access_code);
    } catch (Throwable $e) {
        MessageUtil::setMessage($e->getMessage(),'Event area','error');
        LocationUtils::redirectInternal('panel/event-execution'.($space?'?code='.$space->access_code:''));
    }
});
$router->run();
