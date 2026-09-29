<?php

use App\Repositories\Connection;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function deliveryPricingDbExecute(Connection $db,string $sql,array $params=[]): void { $db->query($sql);foreach($params as $key=>$value)$db->bind($key,$value);$db->execute(); }
function deliveryPricingOwner(): int { return (int)LoginService::getSession()->getOwner(); }
function deliveryPricingSite(): string { return 'vnvevents'; }
function deliveryPricingTiers(Connection $db,int $owner,string $site): array {$db->query("SELECT * FROM store_delivery_fallback_tiers WHERE id_owner=:owner AND site_key=:site AND status='ACTIVE' ORDER BY min_distance_miles");$db->bind(':owner',$owner);$db->bind(':site',$site);return $db->fetchAll();}

$router->get(function () {
    $owner=deliveryPricingOwner();$site=deliveryPricingSite();$db=new Connection();$service=new DeliveryPricingService($db);
    $db->query("SELECT id,CONCAT(COALESCE(name,''),' ',COALESCE(lastname,'')) customer_name,email FROM users WHERE id_owner=:owner AND level=5 ORDER BY name,lastname LIMIT 300");$db->bind(':owner',$owner);$customers=$db->fetchAll();
    $db->query("SELECT id,guest_name,guest_email,shipping_address_1,shipping_address_2,shipping_city,shipping_state,shipping_zip,requested_delivery_at FROM store_orders WHERE id_owner=:owner AND site_key=:site ORDER BY id DESC LIMIT 200");$db->bind(':owner',$owner);$db->bind(':site',$site);$orders=$db->fetchAll();
    $db->query("SELECT bi.user_id id_user,bi.billing_address_1 address_1,bi.billing_address_2 address_2,bi.billing_city city,bi.billing_state state,bi.billing_zip zip FROM user_billing_info bi JOIN users u ON u.id=bi.user_id WHERE u.id_owner=:owner");$db->bind(':owner',$owner);$addresses=[];foreach($db->fetchAll() as $row)$addresses[(int)$row->id_user]=(array)$row;
    return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>$service->settings($owner,$site),'tiers'=>deliveryPricingTiers($db,$owner,$site),'zones'=>$service->recentZones($owner,$site),'customers'=>$customers,'customerAddresses'=>$addresses,'orders'=>$orders,'quote'=>null]);
});

$router->post(function () {
    $owner=deliveryPricingOwner();$site=deliveryPricingSite();$db=new Connection();$service=new DeliveryPricingService($db);$action=(string)($_POST['action']??'calculate');
    try {
        if($action==='save_settings'){
            $markup=max(0,min(100,(float)($_POST['delivery_markup_percent']??10)));$rounding=max(0,(float)($_POST['delivery_rounding_increment']??1));$minimum=max(0,(float)($_POST['delivery_minimum_fee']??10));$maximum=max(1,(float)($_POST['delivery_max_distance_miles']??30));$cache=max(0,min(60,(int)($_POST['delivery_quote_cache_minutes']??5)));$frequency=strtoupper((string)($_POST['delivery_calibration_frequency']??'MANUAL'));if(!in_array($frequency,['MANUAL','WEEKLY','MONTHLY'],true))$frequency='MANUAL';
            $peak=trim((string)($_POST['delivery_peak_windows_json']??''));if($peak!==''&&json_decode($peak,true)===null)throw new InvalidArgumentException('Peak windows must be valid JSON.');
            deliveryPricingDbExecute($db,"UPDATE store_gourmet_settings SET delivery_markup_percent=:markup,delivery_rounding_increment=:rounding,delivery_minimum_fee=:minimum,delivery_max_distance_miles=:maximum,delivery_quote_cache_minutes=:cache,delivery_calibration_frequency=:frequency,delivery_peak_windows_json=:peak,pickup_name=:pickup_name,pickup_address_1=:pickup_address,pickup_city=:pickup_city,pickup_state=:pickup_state,pickup_zip=:pickup_zip,pickup_latitude=:pickup_lat,pickup_longitude=:pickup_lng,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site",[':markup'=>$markup,':rounding'=>$rounding,':minimum'=>$minimum,':maximum'=>$maximum,':cache'=>$cache,':frequency'=>$frequency,':peak'=>$peak?:null,':pickup_name'=>trim((string)($_POST['pickup_name']??'')),':pickup_address'=>trim((string)($_POST['pickup_address_1']??'')),':pickup_city'=>trim((string)($_POST['pickup_city']??'')),':pickup_state'=>trim((string)($_POST['pickup_state']??'')),':pickup_zip'=>trim((string)($_POST['pickup_zip']??'')),':pickup_lat'=>(float)($_POST['pickup_latitude']??0),':pickup_lng'=>(float)($_POST['pickup_longitude']??0),':owner'=>$owner,':site'=>$site]);
            foreach((array)($_POST['tier_fee']??[]) as $id=>$fee)deliveryPricingDbExecute($db,"UPDATE store_delivery_fallback_tiers SET customer_fee=:fee WHERE id=:id AND id_owner=:owner AND site_key=:site",[':fee'=>max(0,(float)$fee),':id'=>(int)$id,':owner'=>$owner,':site'=>$site]);
            MessageUtil::setMessage('Delivery pricing settings saved.');LocationUtils::reload();
        }
        if($action==='refresh_zone'){
            $zoneId=(int)($_POST['zone_id']??0);$db->query("SELECT * FROM store_delivery_reference_zones WHERE id=:id AND id_owner=:owner AND site_key=:site AND status='ACTIVE'");$db->bind(':id',$zoneId);$db->bind(':owner',$owner);$db->bind(':site',$site);$zone=$db->fetchOne();if(!$zone)throw new RuntimeException('Reference zone not found.');
            $parts=array_map('trim',explode(',',(string)$zone->representative_address));$quote=$service->quoteDelivery($owner,$site,['address_1'=>$parts[0]??$zone->representative_address,'address_2'=>'','city'=>$parts[1]??$zone->zone_name,'state'=>'FL','zip'=>(string)$zone->zip_code,'country'=>'US'],null,'CALIBRATION');
            deliveryPricingDbExecute($db,"UPDATE store_delivery_reference_zones SET latitude=:lat,longitude=:lng,last_calibrated_at=NOW() WHERE id=:id",[':lat'=>$quote['destination']['latitude']??null,':lng'=>$quote['destination']['longitude']??null,':id'=>$zoneId]);MessageUtil::setMessage('Market reference refreshed for '.$zone->zone_name.'.');LocationUtils::reload();
        }
        if($action==='refresh_all_zones'){
            $db->query("SELECT * FROM store_delivery_reference_zones WHERE id_owner=:owner AND site_key=:site AND status='ACTIVE' ORDER BY sort_order,id");$db->bind(':owner',$owner);$db->bind(':site',$site);$zones=$db->fetchAll();$updated=0;$failed=0;
            foreach($zones as $zone){try{$parts=array_map('trim',explode(',',(string)$zone->representative_address));$quote=$service->quoteDelivery($owner,$site,['address_1'=>$parts[0]??$zone->representative_address,'address_2'=>'','city'=>$parts[1]??$zone->zone_name,'state'=>'FL','zip'=>(string)$zone->zip_code,'country'=>'US'],null,'CALIBRATION');deliveryPricingDbExecute($db,"UPDATE store_delivery_reference_zones SET latitude=:lat,longitude=:lng,last_calibrated_at=NOW() WHERE id=:id",[':lat'=>$quote['destination']['latitude']??null,':lng'=>$quote['destination']['longitude']??null,':id'=>(int)$zone->id]);$updated++;}catch(Throwable $zoneError){$failed++;error_log('[Delivery calibration] '.$zone->zone_name.': '.$zoneError->getMessage());}}
            MessageUtil::setMessage("Market references refreshed: {$updated} succeeded, {$failed} failed. No deliveries were created.");LocationUtils::reload();
        }
        $quote=$service->quoteDelivery($owner,$site,['address_1'=>trim((string)($_POST['address_1']??'')),'address_2'=>trim((string)($_POST['address_2']??'')),'city'=>trim((string)($_POST['city']??'')),'state'=>trim((string)($_POST['state']??'FL')),'zip'=>trim((string)($_POST['zip']??'')),'country'=>'US'],trim((string)($_POST['requested_delivery_at']??''))?:null,'ADMIN_CHECKER',!empty($_POST['order_id'])?(int)$_POST['order_id']:null);
        $db->query("SELECT id,CONCAT(COALESCE(name,''),' ',COALESCE(lastname,'')) customer_name,email FROM users WHERE id_owner=:owner AND level=5 ORDER BY name,lastname LIMIT 300");$db->bind(':owner',$owner);$customers=$db->fetchAll();
        $db->query("SELECT id,guest_name,guest_email,shipping_address_1,shipping_address_2,shipping_city,shipping_state,shipping_zip,requested_delivery_at FROM store_orders WHERE id_owner=:owner AND site_key=:site ORDER BY id DESC LIMIT 200");$db->bind(':owner',$owner);$db->bind(':site',$site);$orders=$db->fetchAll();
        $db->query("SELECT bi.user_id id_user,bi.billing_address_1 address_1,bi.billing_address_2 address_2,bi.billing_city city,bi.billing_state state,bi.billing_zip zip FROM user_billing_info bi JOIN users u ON u.id=bi.user_id WHERE u.id_owner=:owner");$db->bind(':owner',$owner);$addresses=[];foreach($db->fetchAll() as $row)$addresses[(int)$row->id_user]=(array)$row;
        return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>$service->settings($owner,$site),'tiers'=>deliveryPricingTiers($db,$owner,$site),'zones'=>$service->recentZones($owner,$site),'customers'=>$customers,'customerAddresses'=>$addresses,'orders'=>$orders,'quote'=>$quote]);
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage());LocationUtils::reload();}
});
$router->run();
