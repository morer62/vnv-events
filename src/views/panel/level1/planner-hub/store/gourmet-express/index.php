<?php

use App\Repositories\Connection;
use App\Services\GourmetExpressService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$load = static function (): array {
    $owner=(int)LoginService::getSession()->getOwner();$site='vnvevents';$db=new Connection();$service=new GourmetExpressService($db);
    $db->query("SELECT * FROM store_gourmet_delivery_zones WHERE id_owner=:owner AND site_key=:site ORDER BY id");$db->bind(':owner',$owner);$db->bind(':site',$site);$zones=$db->fetchAll();
    $db->query("SELECT * FROM store_gourmet_windows WHERE id_owner=:owner AND site_key=:site ORDER BY sort_order");$db->bind(':owner',$owner);$db->bind(':site',$site);$windows=$db->fetchAll();
    $db->query("SELECT COUNT(*) orders,COALESCE(SUM(total),0) revenue,COALESCE(AVG(total),0) average_order FROM store_orders WHERE id_owner=:owner AND site_key=:site AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND status<>'CANCELLED'");$db->bind(':owner',$owner);$db->bind(':site',$site);$kpis=$db->fetchOne();
    $db->query("SELECT COUNT(*) pending_photos FROM store_products WHERE id_owner=:owner AND site_key=:site AND brand_name='VNV Gourmet Express' AND status='DRAFT'");$db->bind(':owner',$owner);$db->bind(':site',$site);$drafts=(int)($db->fetchOne()->pending_photos??0);
    return compact('owner','site','service','zones','windows','kpis','drafts');
};
$router->get(function() use($load){$d=$load();return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>$d['service']->settings($d['owner'],$d['site']),'zones'=>$d['zones'],'windows'=>$d['windows'],'kpis'=>$d['kpis'],'drafts'=>$d['drafts']]);});
$router->post(function() use($load){$d=$load();$db=new Connection();$action=(string)($_POST['action']??'save');$isAjax=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';try{
    if($action==='toggle_pause'){$paused=!empty($_POST['store_paused'])?1:0;$reopen=$paused?(trim((string)($_POST['reopen_at']??''))?:null):null;$openMessage=trim((string)($_POST['open_message']??''))?:'We are open and accepting VNV Gourmet Express orders.';$pauseMessage=trim((string)($_POST['pause_message']??''))?:'VNV Gourmet Express is temporarily pausing new orders. You can still browse the menu.';$db->query("UPDATE store_gourmet_settings SET store_paused=:paused,open_message=:open_message,pause_message=:pause_message,reopen_at=:reopen,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site");$db->bind(':paused',$paused);$db->bind(':open_message',$openMessage);$db->bind(':pause_message',$pauseMessage);$db->bind(':reopen',$reopen);$db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();if($isAjax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'paused'=>(bool)$paused,'status'=>$paused?'Closed':'Open','message'=>$paused?$pauseMessage:$openMessage,'reopen_at'=>$reopen],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;}}
    else {
        $db->query("UPDATE store_gourmet_settings SET pickup_minimum=:pickup,delivery_minimum=:delivery,daily_capacity=:daily,window_capacity=:window,minimum_order_notice_hours=:notice,dropoff_disclaimer=:disclaimer,staff_phone=:phone,staff_whatsapp=:whatsapp,staff_service_url=:service_url,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site");
        $db->bind(':pickup',max(0,(float)($_POST['pickup_minimum']??80)));
        $db->bind(':delivery',max(0,(float)($_POST['delivery_minimum']??150)));
        $db->bind(':daily',max(1,(int)($_POST['daily_capacity']??25)));
        $db->bind(':window',max(1,(int)($_POST['window_capacity']??6)));
        $db->bind(':notice',max(1,(int)($_POST['minimum_order_notice_hours']??24)));
        $db->bind(':disclaimer',trim((string)($_POST['dropoff_disclaimer']??'')));
        $db->bind(':phone',preg_replace('/\D+/','',(string)($_POST['staff_phone']??'')));
        $db->bind(':whatsapp',preg_replace('/\D+/','',(string)($_POST['staff_whatsapp']??'')));
        $db->bind(':service_url',trim((string)($_POST['staff_service_url']??'/service/catering')));
        $db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();
        foreach((array)($_POST['zone_fee']??[]) as $id=>$fee){$db->query("UPDATE store_gourmet_delivery_zones SET delivery_fee=:fee,free_delivery_threshold=:free WHERE id=:id AND id_owner=:owner AND site_key=:site");$db->bind(':fee',max(0,(float)$fee));$db->bind(':free',max(0,(float)($_POST['zone_free'][$id]??0)));$db->bind(':id',(int)$id);$db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();}
        foreach((array)($_POST['window_capacity_row']??[]) as $id=>$capacity){$db->query("UPDATE store_gourmet_windows SET capacity=:capacity WHERE id=:id AND id_owner=:owner AND site_key=:site");$db->bind(':capacity',max(1,(int)$capacity));$db->bind(':id',(int)$id);$db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();}
    }
    MessageUtil::setMessage($action==='toggle_pause'?'VNV Gourmet Express is now '.($paused?'closed':'open').'.':'VNV Gourmet Express settings saved.');
}catch(Throwable $e){if($isAjax){http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);return;}MessageUtil::setMessage($e->getMessage());}LocationUtils::reload();});
$router->run();
