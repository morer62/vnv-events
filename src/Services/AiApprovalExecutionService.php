<?php
namespace App\Services;

use App\Repositories\Connection;
use App\Repositories\OrdersRepository;
use RuntimeException;

final class AiApprovalExecutionService
{
    public function execute(object $approval,int $ownerId): array
    {
        if((string)$approval->status==='EXECUTED')return $this->lastSuccess((int)$approval->id,$ownerId);
        if((string)$approval->status!=='APPROVED')throw new RuntimeException('Approve the draft before executing the final action.');
        $db=new Connection();$lock='approval:'.(int)$approval->id;$token=bin2hex(random_bytes(32));
        $db->query("INSERT INTO ai_agent_execution_locks(lock_key,lock_token,locked_until) VALUES(:k,:t,DATE_ADD(NOW(),INTERVAL 3 MINUTE))
          ON DUPLICATE KEY UPDATE lock_token=IF(locked_until<NOW(),VALUES(lock_token),lock_token),locked_until=IF(locked_until<NOW(),VALUES(locked_until),locked_until)");
        $db->bind(':k',$lock);$db->bind(':t',$token);$db->execute();
        $db->query("SELECT lock_token FROM ai_agent_execution_locks WHERE lock_key=:k");$db->bind(':k',$lock);$held=$db->fetchOne();
        if(!$held||!hash_equals($token,(string)$held->lock_token))throw new RuntimeException('This action is already being executed. Please wait.');
        $payload=json_decode((string)$approval->payload_json,true)?:[];
        $db->query("SELECT COUNT(*) n FROM ai_agent_approval_executions WHERE id_approval=:id");$db->bind(':id',(int)$approval->id);$count=$db->fetchOne();
        $attempt=(int)$count->n+1;
        $db->query("INSERT INTO ai_agent_approval_executions(id_approval,id_owner,attempt) VALUES(:id,:owner,:attempt)");
        $db->bind(':id',(int)$approval->id);$db->bind(':owner',$ownerId);$db->bind(':attempt',$attempt);$db->execute();$executionId=(int)$db->lastId();
        try{
            $result=match((string)$approval->action_type){
                'PUBLISH_SOCIAL'=>(new SocialPublishingService())->publish($ownerId,(string)($payload['platform']??''),$payload),
                'PUBLISH_CAROUSEL'=>(new SocialPublishingService())->publish($ownerId,(string)($payload['platform']??($payload['platforms'][0]??'instagram')),$payload),
                'PUBLISH_SOCIAL_CREATIVE'=>(new SocialPublishingService())->publish($ownerId,(string)($payload['platform']??($payload['platforms'][0]??'instagram')),$payload),
                'PUBLISH_ARTICLE'=>$this->publishArticle($ownerId,$payload),
                'REVIEW_SHORT_VIDEO'=>$this->queueShortVideo($ownerId,$payload),
                'CREATE_ESTIMATE_DRAFT'=>$this->createEstimateDraft($ownerId,$payload),
                'SEND_FOLLOW_UP','SEND_REVIEW_REQUEST','SEND_CONCIERGE_RESPONSE'=>$this->sendApprovedEmail($ownerId,$payload,(string)$approval->title),
                'SEND_META_RESPONSE'=>(new MetaMessagingService())->send($ownerId,$payload),
                default=>throw new RuntimeException('This approval is a reviewed handoff; its final action is completed in the linked module.'),
            };
            $external=(string)($result['id']??$result['external_reference']??'');
            $db->query("UPDATE ai_agent_approval_executions SET status='SUCCEEDED',external_reference=:ref,response_json=:response,finished_at=NOW() WHERE id=:id");
            $db->bind(':ref',$external?:null);$db->bind(':response',json_encode($result,JSON_UNESCAPED_SLASHES));$db->bind(':id',$executionId);$db->execute();
            $db->query("UPDATE ai_agent_approvals SET status='EXECUTED' WHERE id=:id AND id_owner=:owner AND status='APPROVED'");
            $db->bind(':id',(int)$approval->id);$db->bind(':owner',$ownerId);$db->execute();
            return $result;
        }catch(\Throwable $e){
            $retry=$attempt<3;$db->query("UPDATE ai_agent_approval_executions SET status=:status,error_message=:error,next_retry_at=".($retry?'DATE_ADD(NOW(),INTERVAL '.(2**$attempt).' MINUTE)':'NULL').",finished_at=NOW() WHERE id=:id");
            $db->bind(':status',$retry?'RETRY':'FAILED');$db->bind(':error',$e->getMessage());$db->bind(':id',$executionId);$db->execute();throw $e;
        }finally{$db->query("DELETE FROM ai_agent_execution_locks WHERE lock_key=:k AND lock_token=:t");$db->bind(':k',$lock);$db->bind(':t',$token);$db->execute();}
    }

    public function history(int $approvalId,int $ownerId): array
    {
        $db=new Connection();$db->query("SELECT * FROM ai_agent_approval_executions WHERE id_approval=:id AND id_owner=:owner ORDER BY id DESC");$db->bind(':id',$approvalId);$db->bind(':owner',$ownerId);return $db->fetchAll();
    }
    private function lastSuccess(int $id,int $owner): array{$rows=$this->history($id,$owner);foreach($rows as $r)if($r->status==='SUCCEEDED')return json_decode((string)$r->response_json,true)?:['external_reference'=>$r->external_reference];return ['status'=>'already_executed'];}
    private function publishArticle(int $owner,array $p): array{$id=(int)($p['content_id']??0);if(!$id)throw new RuntimeException('Article id is missing.');$db=new Connection();$db->query("UPDATE cms_contents SET status='PUBLISHED',published_at=COALESCE(published_at,NOW()),updated_at=NOW() WHERE id=:id AND id_owner=:owner");$db->bind(':id',$id);$db->bind(':owner',$owner);$db->execute();return ['id'=>(string)$id,'status'=>'PUBLISHED'];}
    private function queueShortVideo(int $owner,array $p): array{$id=(int)($p['media_job_id']??0);$db=new Connection();$db->query("UPDATE ai_agent_media_jobs SET status='QUEUED',updated_at=NOW() WHERE id=:id AND id_owner=:owner AND status IN ('READY','COMPLETED')");$db->bind(':id',$id);$db->bind(':owner',$owner);$db->execute();return ['id'=>(string)$id,'status'=>'QUEUED'];}
    private function createEstimateDraft(int $owner,array $p): array
    {
        $leadId=(int)($p['lead_id']??0);$estimate=$p['estimate']??[];$db=new Connection();
        $db->query("SELECT * FROM crm_leads WHERE id=:id AND id_owner=:owner");$db->bind(':id',$leadId);$db->bind(':owner',$owner);$lead=$db->fetchOne();
        if(!$lead)throw new RuntimeException('CRM lead no longer exists.');
        $date=(string)($estimate['event_date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Confirm a valid event date in the approval before creating the order draft.');
        $clientId=(int)($lead->id_user??0);
        if(!$clientId){$email=trim((string)($p['email']??$estimate['email']??$lead->email??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Confirm the client email before creating the estimate.');
            $client=StoreCustomerService::findOrCreateLevel5User($owner,(string)($lead->name??'VNV Client'),$email,(string)($lead->phone??''));if(!$client)throw new RuntimeException('The client account could not be found or created.');$clientId=(int)$client->id;
            $db->query("UPDATE crm_leads SET id_user=:user WHERE id=:id AND id_owner=:owner");$db->bind(':user',$clientId);$db->bind(':id',$leadId);$db->bind(':owner',$owner);$db->execute();}
        $orderId=(new OrdersRepository())->addWithExplicitOwner([
            'id_owner'=>$owner,'id_user'=>$owner,'id_client'=>$clientId,'event_date'=>$date,
            'address'=>(string)($estimate['location']??$lead->address??''),'start_time'=>(string)($estimate['start_time']??'18:00:00'),
            'end_time'=>(string)($estimate['end_time']??'22:00:00'),'payment_status'=>'pending','status_workflow'=>'INVOICE_DRAFT',
            'notes'=>"AI estimate draft from CRM lead #{$leadId}\n".json_encode($estimate,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'payment_split_type'=>2,'payment_split_percent_1'=>50,'payment_split_percent_2'=>50,'total_team_needed'=>0,
        ]);
        if(!$orderId)throw new RuntimeException('The order draft could not be created.');
        $assigned=[];foreach(array_slice((array)($estimate['requested_services']??[]),0,20) as $requested){$name=trim(is_array($requested)?(string)($requested['name']??''):(string)$requested);if($name==='')continue;
            $db->query("SELECT id,name,price,description FROM orders_services WHERE id_owner=:owner AND is_archived=0 AND name LIKE :name ORDER BY (name=:exact) DESC LIMIT 1");$db->bind(':owner',$owner);$db->bind(':name','%'.$name.'%');$db->bind(':exact',$name);$service=$db->fetchOne();if(!$service)continue;
            $db->query("INSERT INTO orders_services_assigned(id_order,id_service,quantity,unit_price,description,subtotal,id_owner,is_variable,variable_price) VALUES(:order,:service,1,:price,:description,:price,:owner,'NO',NULL)");
            $db->bind(':order',$orderId);$db->bind(':service',(int)$service->id);$db->bind(':price',(float)$service->price);$db->bind(':description',$service->description);$db->bind(':owner',$owner);$db->execute();$assigned[]=['id'=>(int)$service->id,'name'=>$service->name];
        }
        return ['id'=>(string)$orderId,'status'=>'INVOICE_DRAFT','external_reference'=>'order:'.$orderId,'assigned_services'=>$assigned];
    }
    private function sendApprovedEmail(int $owner,array $p,string $subject): array
    {
        $to=trim((string)($p['client_email']??''));if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('The approved action has no valid recipient email.');
        $message=trim((string)($p['draft_message']??''));if($message==='')throw new RuntimeException('The approved email is empty.');
        $result=EmailServiceFactory::sendWithOwnerProvider($owner,$to,'VNV Events — '.$subject,nl2br(htmlspecialchars($message,ENT_QUOTES,'UTF-8')),true);
        if(empty($result['success']))throw new RuntimeException((string)($result['message']??'The email could not be sent.'));
        return ['id'=>(string)($result['message_id']??bin2hex(random_bytes(8))),'status'=>'SENT','recipient'=>$to];
    }
}
