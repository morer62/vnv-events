<?php
namespace App\Services;

use App\Repositories\AiAgentConnectionsRepository;
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use RuntimeException;

final class MetaMessagingService
{
    public function send(int $owner,array $payload): array
    {
        $channel=(string)($payload['channel']??'');$platform=$channel==='messenger'?'facebook':$channel;
        if(!in_array($platform,['facebook','instagram','whatsapp'],true))throw new RuntimeException('Unsupported Meta messaging channel.');
        $agent=(new AiAgentsRepository())->find($owner,'social_publisher');$c=(new AiAgentConnectionsRepository())->credentials($owner,(int)$agent->id,$platform);
        $recipient=(string)($payload['external_user_id']??'');$message=trim((string)($payload['draft_message']??''));if($recipient===''||$message==='')throw new RuntimeException('Recipient or approved message is missing.');
        $graph='https://graph.facebook.com/'.trim((string)($_ENV['META_GRAPH_VERSION']??'v23.0'),'/').'/'.rawurlencode($c['account_identifier']).'/messages';
        $body=$platform==='whatsapp'?['messaging_product'=>'whatsapp','to'=>$recipient,'type'=>'text','text'=>['body'=>$message]]:['recipient'=>['id'=>$recipient],'message'=>['text'=>$message],'messaging_type'=>'RESPONSE'];
        $ch=curl_init($graph);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$c['access_token'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_SLASHES)]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);$result=json_decode((string)$raw,true);
        if($raw===false||$status<200||$status>=300)throw new RuntimeException('Meta messaging failed: '.($result['error']['message']??$error??'HTTP '.$status));
        if(!empty($payload['conversation_id'])){$db=new Connection();$db->query("INSERT INTO ai_agent_conversation_messages(id_conversation,direction,external_message_id,message_text,payload_json) VALUES(:id,'OUT',:external,:text,:payload)");
            $db->bind(':id',(int)$payload['conversation_id']);$db->bind(':external',(string)($result['message_id']??$result['messages'][0]['id']??''));$db->bind(':text',$message);$db->bind(':payload',json_encode($result));$db->execute();
            $db->query("UPDATE ai_agent_conversations SET status='OPEN',last_message_at=NOW() WHERE id=:id");$db->bind(':id',(int)$payload['conversation_id']);$db->execute();}
        return $result?:['status'=>$status];
    }
}
