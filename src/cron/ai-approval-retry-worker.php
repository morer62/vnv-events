<?php
use App\Repositories\Connection;
use App\Services\AiApprovalExecutionService;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$db=new Connection();$limit=max(1,min(25,(int)($argv[1]??10)));
$db->query("SELECT p.* FROM ai_agent_approvals p JOIN ai_agent_approval_executions e ON e.id_approval=p.id
 WHERE p.status='APPROVED' AND e.status='RETRY' AND e.next_retry_at<=NOW()
 AND e.id=(SELECT MAX(e2.id) FROM ai_agent_approval_executions e2 WHERE e2.id_approval=p.id)
 ORDER BY e.next_retry_at LIMIT {$limit}");
foreach($db->fetchAll() as $approval){
    try{(new AiApprovalExecutionService())->execute($approval,(int)$approval->id_owner);echo "Executed approval #{$approval->id}\n";}
    catch(\Throwable $e){fwrite(STDERR,"Approval #{$approval->id}: {$e->getMessage()}\n");}
}
