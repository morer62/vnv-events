<?php
use App\Repositories\AiAgentsRepository;
use App\Services\AiApprovalExecutionService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$repo=new AiAgentsRepository();
    $tab=(string)($_GET['tab']??'all');$status=strtoupper((string)($_GET['status']??'PENDING'));$search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$perPage=30;
    $total=$repo->approvalInboxTotal($owner,$tab,$status,$search);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
    return TemplateResponse::render(__DIR__.'/index.twig',['items'=>$repo->approvalInbox($owner,$tab,$status,$search,$page,$perPage),'counts'=>$repo->approvalCounts($owner),'tab'=>$tab,'status'=>$status,'search'=>$search,'page'=>$page,'pages'=>$pages,'total'=>$total]);
});
$router->post(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$user=(int)$session->getId();$repo=new AiAgentsRepository();
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['approval_ids']??[])))));$action=(string)($_POST['bulk_action']??'');
    try{
        if(!$ids)throw new RuntimeException('Select at least one approval.');
        if(count($ids)>50)throw new RuntimeException('Process at most 50 items per batch.');
        $done=0;foreach($ids as $id){$approval=$repo->findApproval($owner,$id);if(!$approval)continue;
            if(in_array($action,['approve','reject'],true)&&$approval->status==='PENDING'){$repo->reviewApproval($owner,$id,$action==='approve'?'APPROVED':'REJECTED',$user,'Batch decision');$done++;}
            elseif($action==='execute'&&$approval->status==='APPROVED'){(new AiApprovalExecutionService())->execute($approval,$owner);$done++;}
        }MessageUtil::setMessage($done.' approval action(s) completed.');
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Approval Center','danger');}
    LocationUtils::redirectInternal('panel/agents/approvals?tab='.rawurlencode((string)($_POST['tab']??'all')).'&status='.rawurlencode((string)($_POST['status']??'PENDING')));
});
$router->run();
