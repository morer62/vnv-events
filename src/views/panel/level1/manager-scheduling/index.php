<?php

use App\Repositories\Connection;
use App\Services\LoginService;
use App\Services\ManagerAvailabilityService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $owner = $user->getOwner();
    $db = new Connection();
    $db->query("SELECT o.id,o.event_date,o.start_time,o.end_time,o.setup_minutes,o.main_manager_id,o.manager_assignment_status,o.availability_status,CONCAT(c.name,' ',c.lastname) client_name,CONCAT(m.name,' ',m.lastname) manager_name FROM orders o LEFT JOIN users c ON c.id=o.id_client LEFT JOIN users m ON m.id=o.main_manager_id WHERE o.id_owner=:owner AND o.is_archived=0 AND o.event_date>=CURDATE() ORDER BY o.event_date,o.start_time");
    $db->bind(':owner',$owner);
    $orders=$db->fetchAll();

    $db->query("SELECT u.id,u.name,u.lastname,u.email,u.level,COALESCE(p.is_event_manager,0) is_event_manager FROM users u LEFT JOIN event_manager_profiles p ON p.manager_id=u.id AND p.id_owner=:owner WHERE u.id_owner=:owner AND u.level=4 AND u.is_active=1 ORDER BY u.name,u.lastname");
    $db->bind(':owner',$owner);
    $candidates=$db->fetchAll();
    $managers=array_values(array_filter($candidates,fn($manager)=>(int)$manager->is_event_manager===1));

    $db->query("SELECT id,name,lastname,email,level,0 is_event_manager FROM users WHERE is_active=1 AND level=1 AND (id=:owner OR LOWER(email)=LOWER(:email)) ORDER BY id=:owner DESC LIMIT 1");
    $db->bind(':owner',$owner);$db->bind(':email',$_ENV['VNV_DEFAULT_MANAGER_EMAIL']??'info@vnvevents.com');
    $fallback=$db->fetchOne();
    if($fallback)$managers[]=$fallback;

    return TemplateResponse::render(__DIR__.'/index.twig',[...UserContext::get(),'orders'=>$orders,'managers'=>$managers,'candidates'=>$candidates]);
});

$router->post(function () {
    $user=LoginService::getSession();
    $owner=$user->getOwner();
    $db=new Connection();
    if(($_POST['action']??'assign')==='toggle_manager'){
        $manager=(int)($_POST['manager_id']??0);$enabled=!empty($_POST['enabled'])?1:0;
        $db->query("INSERT INTO event_manager_profiles(id_owner,manager_id,is_event_manager,updated_by) SELECT :owner,u.id,:enabled,:user FROM users u WHERE u.id=:manager AND u.id_owner=:owner AND u.level=4 ON DUPLICATE KEY UPDATE is_event_manager=VALUES(is_event_manager),updated_by=VALUES(updated_by)");
        foreach(['owner'=>$owner,'manager'=>$manager,'enabled'=>$enabled,'user'=>$user->getId()] as $key=>$value)$db->bind(':'.$key,$value);
        $db->execute();
        MessageUtil::setMessage($enabled?'Event Manager enabled.':'Event Manager disabled. Future assignments must be reassigned before team deactivation.');
        LocationUtils::redirectInternal('panel/manager-scheduling');
    }

    $orderId=(int)($_POST['order_id']??0);$managerId=(int)($_POST['manager_id']??0);
    $db->query("SELECT * FROM orders WHERE id=:id AND id_owner=:owner");$db->bind(':id',$orderId);$db->bind(':owner',$owner);$order=$db->fetchOne();
    if(!$order||!$managerId){MessageUtil::setMessage('Order and manager are required.','Assignment not saved','warning');LocationUtils::redirectInternal('panel/manager-scheduling');}

    $engine=new ManagerAvailabilityService();
    $result=$engine->evaluateOrder($order,$managerId);
    $check=$engine->record($owner,'REASSIGNMENT',$orderId,$result,$managerId,$user->getId());
    $reason=trim((string)($_POST['override_reason']??''));
    if($result['status']!==ManagerAvailabilityService::AVAILABLE&&$reason===''){
        MessageUtil::setMessage($result['message'].' No reassignment was made.','Manager Availability Conflict','warning');
        LocationUtils::redirectInternal('panel/manager-scheduling');
    }
    if($result['status']!==ManagerAvailabilityService::AVAILABLE){
        $db->query("INSERT INTO manager_availability_overrides(id_owner,context_type,context_id,check_id,authorized_by,reason,conflict_snapshot_json) VALUES(:owner,'REASSIGNMENT',:context,:check,:user,:reason,:snapshot)");
        foreach(['owner'=>$owner,'context'=>$orderId,'check'=>$check,'user'=>$user->getId(),'reason'=>$reason,'snapshot'=>json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)] as $key=>$value)$db->bind(':'.$key,$value);
        $db->execute();
    }

    $previous=$order->main_manager_id?:null;
    $status=$result['status']===ManagerAvailabilityService::AVAILABLE?'AVAILABLE':'OVERRIDDEN';
    $db->query("UPDATE orders SET main_manager_id=:manager,manager_assignment_status='ASSIGNED',availability_status=:status,availability_checked_at=NOW() WHERE id=:id AND id_owner=:owner");
    $db->bind(':manager',$managerId);$db->bind(':status',$status);$db->bind(':id',$orderId);$db->bind(':owner',$owner);$db->execute();
    $db->query("INSERT INTO manager_assignment_history(id_owner,order_id,previous_manager_id,new_manager_id,action,note,changed_by) VALUES(:owner,:order,:previous,:manager,'REASSIGNED',:note,:user)");
    foreach(['owner'=>$owner,'order'=>$orderId,'previous'=>$previous,'manager'=>$managerId,'note'=>$reason?:'Availability validated','user'=>$user->getId()] as $key=>$value)$db->bind(':'.$key,$value);
    $db->execute();
    MessageUtil::setMessage('Main Manager assigned and availability recorded.');
    LocationUtils::redirectInternal('panel/manager-scheduling');
});

$router->run();
