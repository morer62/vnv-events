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
$router->post(function() use($load){$d=$load();$db=new Connection();$action=(string)($_POST['action']??'save');try{
    if($action==='toggle_pause'){$paused=!empty($_POST['store_paused'])?1:0;$reopen=trim((string)($_POST['reopen_at']??''))?:null;$db->query("UPDATE store_gourmet_settings SET store_paused=:paused,pause_message=:message,reopen_at=:reopen,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site");$db->bind(':paused',$paused);$db->bind(':message',trim((string)($_POST['pause_message']??''))?:null);$db->bind(':reopen',$reopen);$db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();}
    else {
        $db->query("UPDATE store_gourmet_settings SET pickup_minimum=:pickup,delivery_minimum=:delivery,daily_capacity=:daily,window_capacity=:window,minimum_order_notice_hours=:notice,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site");
        $db->bind(':pickup',max(0,(float)($_POST['pickup_minimum']??80)));
        $db->bind(':delivery',max(0,(float)($_POST['delivery_minimum']??150)));
        $db->bind(':daily',max(1,(int)($_POST['daily_capacity']??25)));
        $db->bind(':window',max(1,(int)($_POST['window_capacity']??6)));
        $db->bind(':notice',max(1,(int)($_POST['minimum_order_notice_hours']??24)));
        $db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();
        foreach((array)($_POST['zone_fee']??[]) as $id=>$fee){$db->query("UPDATE store_gourmet_delivery_zones SET delivery_fee=:fee,free_delivery_threshold=:free WHERE id=:id AND id_owner=:owner AND site_key=:site");$db->bind(':fee',max(0,(float)$fee));$db->bind(':free',max(0,(float)($_POST['zone_free'][$id]??0)));$db->bind(':id',(int)$id);$db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();}
        foreach((array)($_POST['window_capacity_row']??[]) as $id=>$capacity){$db->query("UPDATE store_gourmet_windows SET capacity=:capacity WHERE id=:id AND id_owner=:owner AND site_key=:site");$db->bind(':capacity',max(1,(int)$capacity));$db->bind(':id',(int)$id);$db->bind(':owner',$d['owner']);$db->bind(':site',$d['site']);$db->execute();}
    }
    MessageUtil::setMessage('VNV Gourmet Express settings saved.');
}catch(Throwable $e){MessageUtil::setMessage($e->getMessage());}LocationUtils::reload();});
$router->run();
