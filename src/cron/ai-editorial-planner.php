<?php
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use App\Services\AiAgentRegistry;
use App\Services\AiModelGateway;
use App\Services\AiTrendService;
use Dotenv\Dotenv;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}require_once dirname(__DIR__,2).'/vendor/autoload.php';Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$db=new Connection();$db->query("SELECT * FROM ai_agent_editorial_plans WHERE enabled=1 AND (last_planned_at IS NULL OR last_planned_at<DATE_SUB(NOW(),INTERVAL 7 DAY)) ORDER BY id LIMIT 20");
foreach($db->fetchAll() as $plan)try{
 $db->query("UPDATE ai_agent_editorial_plans SET last_planned_at=NOW() WHERE id=:id AND (last_planned_at IS NULL OR last_planned_at<DATE_SUB(NOW(),INTERVAL 7 DAY))");$db->bind(':id',(int)$plan->id);$db->execute();if($db->rowCount()!==1)continue;
 $db->query("SELECT id,title,content_type,status,updated_at FROM cms_contents WHERE id_owner=:owner ORDER BY updated_at DESC LIMIT 100");$db->bind(':owner',(int)$plan->id_owner);$library=$db->fetchAll();
 $result=(new AiModelGateway())->json((int)$plan->id_owner,'openai','Create a seven-day VNV Events production plan that exactly respects quotas and uses trends only as format signals. Never publish.',['quotas'=>$plan,'library'=>$library,'video_signals'=>(new AiTrendService())->currentVideoSignals()],['week_summary'=>'','items'=>[['day'=>'','content_type'=>'article|location|page|social|video','title'=>'','angle'=>'','source_or_service'=>'','networks'=>[],'approval_required'=>true]]]);
 $repo=new AiAgentsRepository();$repo->seed((int)$plan->id_owner,AiAgentRegistry::definitions());$agent=$repo->find((int)$plan->id_owner,'content_refresh');$run=$repo->createRun((int)$agent->id,(int)$plan->id_owner,'SCHEDULE',null,(array)$plan);$repo->finishRun($run,'AWAITING_APPROVAL',$result);$repo->createApproval($run,(int)$agent->id,(int)$plan->id_owner,'REVIEW_EDITORIAL_PLAN','Review weekly Growth Hub plan',$result,null);echo "Editorial plan #{$plan->id} ready\n";
}catch(Throwable $e){fwrite(STDERR,"Editorial plan #{$plan->id}: {$e->getMessage()}\n");}
