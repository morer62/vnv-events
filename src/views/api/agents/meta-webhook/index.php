<?php
use App\Repositories\Connection;
use App\Repositories\CrmLeadRepository;
use App\Repositories\AiAgentsRepository;
use App\Repositories\AiAgentConnectionsRepository;

header('Content-Type: application/json; charset=utf-8');
$method=$_SERVER['REQUEST_METHOD']??'GET';
$owner=max(1,(int)($_GET['owner']??$_ENV['META_WEBHOOK_OWNER_ID']??2));$stored=[];
try{$agent=(new AiAgentsRepository())->find($owner,'social_publisher');if($agent)$stored=(new AiAgentConnectionsRepository())->credentials($owner,(int)$agent->id,'facebook');}catch(\Throwable){}
$verifyToken=(string)($stored['verify_token']??$_ENV['META_WEBHOOK_VERIFY_TOKEN']??'');
if($method==='GET'){
    if(($_GET['hub_mode']??$_GET['hub.mode']??'')==='subscribe'&&$verifyToken!==''&&hash_equals($verifyToken,(string)($_GET['hub_verify_token']??$_GET['hub.verify_token']??''))){
        header('Content-Type: text/plain');echo (string)($_GET['hub_challenge']??$_GET['hub.challenge']??'');exit;
    }
    http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Webhook verification failed.']);exit;
}
if($method!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
$raw=(string)file_get_contents('php://input');$secret=(string)($stored['app_secret']??$_ENV['META_APP_SECRET']??'');
$signature=(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??'');
if($secret===''||!str_starts_with($signature,'sha256=')||!hash_equals(substr($signature,7),hash_hmac('sha256',$raw,$secret))){
    http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Invalid Meta signature.']);exit;
}
$payload=json_decode($raw,true);$key=hash('sha256',$raw);
$db=new Connection();$db->query("INSERT IGNORE INTO ai_meta_webhook_events(id_owner,event_key,object_type,payload_json) VALUES(:owner,:key,:object,:payload)");
$db->bind(':owner',$owner);$db->bind(':key',$key);$db->bind(':object',(string)($payload['object']??''));$db->bind(':payload',$raw);$db->execute();
try{
    $messages=[];
    foreach((array)($payload['entry']??[]) as $entry){
        foreach((array)($entry['changes']??[]) as $change){
            $lead=$change['value']['leadgen_id']??null;if($lead)(new CrmLeadRepository())->addWithExplicitOwner(['name'=>'Meta Lead '.$lead,'id_owner'=>$owner,'archived'=>'NO','comments'=>'Meta Lead Ads webhook payload received. Leadgen ID: '.$lead]);
            foreach((array)($change['value']['messages']??[]) as $m)$messages[]=['channel'=>'whatsapp','from'=>(string)($m['from']??''),'id'=>(string)($m['id']??''),'text'=>(string)($m['text']['body']??'')];
        }
        foreach((array)($entry['messaging']??[]) as $m)$messages[]=['channel'=>(($payload['object']??'')==='instagram'?'instagram':'messenger'),'from'=>(string)($m['sender']['id']??''),'id'=>(string)($m['message']['mid']??''),'text'=>(string)($m['message']['text']??'')];
    }
    foreach($messages as $m){if($m['from']===''||$m['text']==='')continue;
        $db->query("INSERT INTO ai_agent_conversations(id_owner,channel,external_user_id,last_message_at) VALUES(:owner,:channel,:external,NOW())
          ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),last_message_at=NOW(),status=IF(status='CLOSED','OPEN',status)");
        $db->bind(':owner',$owner);$db->bind(':channel',$m['channel']);$db->bind(':external',$m['from']);$db->execute();$conversation=(int)$db->lastId();
        $db->query("INSERT IGNORE INTO ai_agent_conversation_messages(id_conversation,direction,external_message_id,message_text,payload_json) VALUES(:conversation,'IN',:external,:text,:payload)");
        $db->bind(':conversation',$conversation);$db->bind(':external',$m['id']?:null);$db->bind(':text',$m['text']);$db->bind(':payload',json_encode($m));$db->execute();
    }
    $db->query("UPDATE ai_meta_webhook_events SET status='PROCESSED',processed_at=NOW() WHERE event_key=:key");$db->bind(':key',$key);$db->execute();
    echo json_encode(['ok'=>true]);
}catch(\Throwable $e){$db->query("UPDATE ai_meta_webhook_events SET status='ERROR',error_message=:error,processed_at=NOW() WHERE event_key=:key");$db->bind(':error',$e->getMessage());$db->bind(':key',$key);$db->execute();http_response_code(500);echo json_encode(['ok'=>false]);}
