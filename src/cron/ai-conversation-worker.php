<?php
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use App\Services\AiAgentRegistry;
use App\Services\AiModelGateway;
use Dotenv\Dotenv;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}require_once dirname(__DIR__,2).'/vendor/autoload.php';Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$db=new Connection();$limit=max(1,min(20,(int)($argv[1]??5)));
$db->query("SELECT c.*,MAX(m.id) newest FROM ai_agent_conversations c JOIN ai_agent_conversation_messages m ON m.id_conversation=c.id AND m.direction='IN'
 WHERE c.status='OPEN' AND (c.last_processed_message_id IS NULL OR m.id>c.last_processed_message_id) GROUP BY c.id ORDER BY c.last_message_at LIMIT {$limit}");
foreach($db->fetchAll() as $conversation)try{
    $db->query("SELECT direction,message_text,created_at FROM ai_agent_conversation_messages WHERE id_conversation=:id ORDER BY id DESC LIMIT 30");$db->bind(':id',(int)$conversation->id);$messages=array_reverse($db->fetchAll());
    $db->query("SELECT c.id,c.title,c.content_type,c.excerpt,c.body_html,r.route
        FROM cms_contents c
        LEFT JOIN cms_routes r ON r.id_content=c.id AND r.is_main=1
        WHERE c.id_owner=:owner AND c.status='PUBLISHED'
        ORDER BY c.updated_at DESC LIMIT 80");
    $db->bind(':owner',(int)$conversation->id_owner);$knowledge=$db->fetchAll();
    $result=(new AiModelGateway())->json((int)$conversation->id_owner,'openai','You are the VNV Events customer concierge. Answer only from supplied VNV content. Never invent availability, pricing, policy, or contract terms. Identify when an estimate is requested and collect email, event date, location, guest count and services. Return JSON only.',['channel'=>$conversation->channel,'conversation'=>$messages,'vnv_knowledge'=>$knowledge],['reply'=>'','needs_human'=>false,'estimate_requested'=>false,'email'=>null,'estimate'=>['event_date'=>null,'location'=>null,'guest_count'=>null,'requested_services'=>[],'missing_information'=>[]]]);
    $repo=new AiAgentsRepository();$repo->seed((int)$conversation->id_owner,AiAgentRegistry::definitions());$agent=$repo->find((int)$conversation->id_owner,'client_concierge');$run=$repo->createRun((int)$agent->id,(int)$conversation->id_owner,'SYSTEM',null,['conversation_id'=>(int)$conversation->id]);
    $action=['type'=>'SEND_META_RESPONSE','title'=>'Reply to '.ucfirst((string)$conversation->channel).' conversation #'.$conversation->id,'channel'=>$conversation->channel,'external_user_id'=>$conversation->external_user_id,'conversation_id'=>(int)$conversation->id,'draft_message'=>(string)$result['reply'],'needs_human'=>(bool)$result['needs_human'],'estimate'=>$result['estimate']??[],'email'=>$result['email']??null];
    $repo->createApproval($run,(int)$agent->id,(int)$conversation->id_owner,'SEND_META_RESPONSE',$action['title'],$action,null);$repo->finishRun($run,'AWAITING_APPROVAL',$result);
    if(!empty($result['estimate_requested'])){
        $leadId=(int)($conversation->id_lead??0);$email=trim((string)($result['email']??''));
        if(!$leadId){$db->query("INSERT INTO crm_leads(name,email,id_owner,archived,comments) VALUES(:name,:email,:owner,'NO',:comments)");
            $db->bind(':name',ucfirst((string)$conversation->channel).' customer '.$conversation->external_user_id);$db->bind(':email',$email?:null);$db->bind(':owner',(int)$conversation->id_owner);$db->bind(':comments','Created from approved Meta conversation intake.');$db->execute();$leadId=(int)$db->lastId();
            $db->query("UPDATE ai_agent_conversations SET id_lead=:lead WHERE id=:id");$db->bind(':lead',$leadId);$db->bind(':id',(int)$conversation->id);$db->execute();}
        $estimateAction=['type'=>'CREATE_ESTIMATE_DRAFT','title'=>'Create estimate draft from conversation #'.$conversation->id,'lead_id'=>$leadId,'email'=>$email,'estimate'=>$result['estimate']??[],'draft_message'=>'Review extracted estimate details before creating the order.'];
        $repo->createApproval($run,(int)$agent->id,(int)$conversation->id_owner,'CREATE_ESTIMATE_DRAFT',$estimateAction['title'],$estimateAction,null);
    }
    $db->query("UPDATE ai_agent_conversations SET status='WAITING_APPROVAL',last_processed_message_id=:message WHERE id=:id");$db->bind(':message',(int)$conversation->newest);$db->bind(':id',(int)$conversation->id);$db->execute();
    echo "Conversation #{$conversation->id} queued for approval\n";
}catch(Throwable $e){fwrite(STDERR,"Conversation #{$conversation->id}: {$e->getMessage()}\n");}
