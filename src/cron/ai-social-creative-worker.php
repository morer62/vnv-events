<?php
declare(strict_types=1);

use App\Repositories\Connection;
use App\Services\AiProviderImageService;

require dirname(__DIR__,2).'/vendor/autoload.php';
$dotenv=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2));$dotenv->safeLoad();

$db=new Connection();
$db->query("SELECT id,id_owner,payload_json FROM ai_agent_approvals
 WHERE status='PENDING' AND action_type IN ('PUBLISH_CAROUSEL','PUBLISH_SOCIAL_CREATIVE')
 AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.image_generation.status')) IN ('QUEUED','PROCESSING')
 ORDER BY id LIMIT 1");
$approval=$db->fetchOne();
if(!$approval){echo "No queued social artwork.\n";exit(0);}
$lock='social-artwork:'.(int)$approval->id;
$db->query("SELECT GET_LOCK(:lock,0) acquired");$db->bind(':lock',$lock);$held=$db->fetchOne();
if(!$held||(int)$held->acquired!==1){echo "Artwork already processing.\n";exit(0);}
try{
    $payload=json_decode((string)$approval->payload_json,true)?:[];$payload['image_generation']['status']='PROCESSING';
    $db->query("UPDATE ai_agent_approvals SET payload_json=:payload WHERE id=:id");$db->bind(':payload',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$db->bind(':id',(int)$approval->id);$db->execute();
    $ratio=str_contains((string)($payload['format']??''),'vertical')?'9:16':'1:1';$provider=(string)($payload['image_generation']['provider']??'openai');$reference=(string)($payload['image_generation']['reference_direction']??'');
    foreach(array_slice((array)($payload['slides']??[]),0,10,true) as $index=>$slide){
        if(!empty($slide['image_url']))continue;
        $prompt='Premium VNV Events social creative, editorial brand design, elegant navy and violet palette, high contrast, polished layout. Avoid unreadable small text. '.(string)($slide['visual_prompt']??'').' Headline context: '.(string)($slide['headline']??'').' Reference direction: '.$reference;
        $image=(new AiProviderImageService())->generate((int)$approval->id_owner,$provider,$prompt,$ratio);$payload['slides'][$index]['image_url']=(string)($image['url']??'');
        $payload['image_url']=(string)($payload['slides'][0]['image_url']??'');$payload['image_generation']['completed_slides']=$index+1;
        $db->query("UPDATE ai_agent_approvals SET payload_json=:payload WHERE id=:id");$db->bind(':payload',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$db->bind(':id',(int)$approval->id);$db->execute();
    }
    $payload['image_generation']['status']='COMPLETED';$payload['image_generation']['error']=null;
    $db->query("UPDATE ai_agent_approvals SET payload_json=:payload WHERE id=:id");$db->bind(':payload',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$db->bind(':id',(int)$approval->id);$db->execute();echo "Artwork completed for approval #{$approval->id}.\n";
}catch(Throwable $e){
    $payload['image_generation']['status']='FAILED';$payload['image_generation']['error']=$e->getMessage();
    $db->query("UPDATE ai_agent_approvals SET payload_json=:payload WHERE id=:id");$db->bind(':payload',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$db->bind(':id',(int)$approval->id);$db->execute();fwrite(STDERR,"Artwork failed: {$e->getMessage()}\n");exit(1);
}finally{$db->query("SELECT RELEASE_LOCK(:lock)");$db->bind(':lock',$lock);$db->fetchOne();}
