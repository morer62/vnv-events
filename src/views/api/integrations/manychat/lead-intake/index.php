<?php
use App\Repositories\LeadIntakeRepository;
use App\Services\ManagerAvailabilityService;
use App\Utils\Router;

$router=new Router();
$router->post(function(){
    header('Content-Type: application/json');
    $expected=(string)($_ENV['MANYCHAT_LEAD_INTAKE_SECRET']??'');$provided=(string)($_SERVER['HTTP_X_VNV_WEBHOOK_SECRET']??'');
    if($expected===''||!hash_equals($expected,$provided)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);return;}
    $payload=json_decode(file_get_contents('php://input'),true);if(!is_array($payload))$payload=$_POST;
    $owner=(int)($_ENV['VNV_EVENTS_OWNER_ID']??2);$date=trim((string)($payload['event_date']??''));$start=trim((string)($payload['start_time']??''));$end=trim((string)($payload['end_time']??''));$setup=max(0,(int)($payload['setup_minutes']??60));
    $result=['status'=>ManagerAvailabilityService::REVIEW,'reason_code'=>'INCOMPLETE_SCHEDULE','message'=>'Date, start time and end time are required.','suggested_manager_id'=>null];
    if($date&&$start&&$end){try{$engine=new ManagerAvailabilityService();$result=$engine->evaluate($owner,$date,$start,$end,$setup);}catch(Throwable $e){$result=['status'=>ManagerAvailabilityService::REVIEW,'reason_code'=>'INVALID_SCHEDULE','message'=>$e->getMessage(),'suggested_manager_id'=>null];}}
    $repo=new LeadIntakeRepository();$id=$repo->upsert($owner,['source'=>'manychat','external_id'=>$payload['external_id']??$payload['subscriber_id']??null,'channel'=>$payload['channel']??null,'contact_name'=>$payload['contact_name']??$payload['name']??null,'email'=>$payload['email']??null,'phone'=>$payload['phone']??null,'service_requested'=>$payload['service']??null,'guest_count'=>isset($payload['guest_count'])?(int)$payload['guest_count']:null,'venue'=>$payload['venue']??null,'event_date'=>$date?:null,'start_time'=>$start?:null,'end_time'=>$end?:null,'setup_minutes'=>$setup?:60,'availability_status'=>$result['status'],'suggested_manager_id'=>$result['suggested_manager_id']??null,'availability_checked_at'=>($date&&$start&&$end)?date('Y-m-d H:i:s'):null,'status'=>$result['status']===ManagerAvailabilityService::AVAILABLE?'QUALIFIED':'NEEDS_REVIEW','payload_json'=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    if($date&&$start&&$end){$engine??=new ManagerAvailabilityService();$engine->record($owner,'LEAD_INTAKE',$id,$result,$result['suggested_manager_id']??null,null);if($result['status']!==ManagerAvailabilityService::AVAILABLE)$engine->notifyLevelOne($owner,'Manager availability review required for Lead Intake #'.$id.'.','panel/lead-intake?id='.$id);}
    echo json_encode(['ok'=>true,'lead_intake_id'=>$id,'availability_status'=>$result['status'],'reason_code'=>$result['reason_code'],'message'=>$result['message'],'suggested_manager_id'=>$result['suggested_manager_id']??null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
});$router->run();
