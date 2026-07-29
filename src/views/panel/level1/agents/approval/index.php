<?php

use App\Repositories\AiAgentsRepository;
use App\Services\AiApprovalRevisionService;
use App\Services\AiApprovalFinalizationService;
use App\Services\AiApprovalExecutionService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$id=(int)($_GET['id']??0);$repo=new AiAgentsRepository();
    $approval=$repo->findApproval($owner,$id);
    if(!$approval){MessageUtil::setMessage('Approval not found.');LocationUtils::redirectInternal('panel/agents');}
    $payload=json_decode((string)$approval->payload_json,true)?:[];
    $draftField=null;
    foreach(['draft_message','body','article','content','caption','copy','brief'] as $candidate){if(isset($payload[$candidate])&&is_string($payload[$candidate])){$draftField=$candidate;break;}}
    $moduleUrl=LocationUtils::pathFor('panel/agents/detail?key='.rawurlencode((string)$approval->agent_key));
    if(!empty($payload['content_id']))$moduleUrl=LocationUtils::pathFor('panel/cms/pages/edit?id='.(int)$payload['content_id']);
    elseif(!empty($payload['order_id']))$moduleUrl=LocationUtils::pathFor('panel/planner-hub/management/orders/orders/edit?id='.(int)$payload['order_id']);
    elseif(!empty($payload['lead_id']))$moduleUrl=LocationUtils::pathFor('panel/planner-hub/management/crm');
    elseif(!empty($payload['media_job_id']))$moduleUrl=LocationUtils::pathFor('panel/growth-hub/video-studio');
    return TemplateResponse::render(__DIR__.'/index.twig',[
        'approval'=>$approval,'payload'=>$payload,'payloadPretty'=>json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'draftField'=>$draftField,'draftValue'=>$draftField?$payload[$draftField]:'','history'=>$repo->approvalHistory($owner,(int)$approval->id_run,(string)$approval->action_type),
        'executions'=>(new AiApprovalExecutionService())->history($id,$owner),
        'canExecute'=>in_array((string)$approval->action_type,['PUBLISH_SOCIAL','PUBLISH_CAROUSEL','PUBLISH_SOCIAL_CREATIVE','PUBLISH_ARTICLE','REVIEW_SHORT_VIDEO','CREATE_ESTIMATE_DRAFT','SEND_FOLLOW_UP','SEND_REVIEW_REQUEST','SEND_CONCIERGE_RESPONSE','SEND_META_RESPONSE'],true),
        'moduleUrl'=>$moduleUrl,
    ]);
});
$router->post(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$user=(int)$session->getId();$id=(int)($_POST['approval_id']??0);$repo=new AiAgentsRepository();$approval=$repo->findApproval($owner,$id);
    if(!$approval){MessageUtil::setMessage('Approval not found.');LocationUtils::redirectInternal('panel/agents');}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='approve'||$action==='reject'){
            if($action==='approve'){
                (new AiApprovalFinalizationService())->apply((string)$approval->action_type,json_decode((string)$approval->payload_json,true)?:[],$owner);
            }
            $repo->reviewApproval($owner,$id,$action==='approve'?'APPROVED':'REJECTED',$user,trim((string)($_POST['review_note']??'')));
            MessageUtil::setMessage($action==='approve'?'Draft approved. You can now complete the final action in its module.':'Draft rejected.');
        }elseif($action==='save_draft'){
            $payload=json_decode((string)$approval->payload_json,true)?:[];$field=(string)($_POST['draft_field']??'');
            if(!in_array($field,['draft_message','body','article','content','caption','copy','brief'],true))throw new RuntimeException('This draft field cannot be edited.');
            $payload[$field]=(string)($_POST['draft_value']??'');$repo->updateApprovalPayload($owner,$id,$payload);MessageUtil::setMessage('Your manual draft changes were saved.');
        }elseif($action==='request_revision'){
            $payload=json_decode((string)$approval->payload_json,true)?:[];$note=trim((string)($_POST['review_note']??''));
            $revised=(new AiApprovalRevisionService())->revise((string)$approval->agent_name,(string)$approval->action_type,$payload,$note);
            $newId=$repo->requestApprovalRevision($owner,$approval,$user,$note,$revised);
            MessageUtil::setMessage('Corrections processed. A new approval is ready.');
            LocationUtils::redirectInternal('panel/agents/approval?id='.$newId);
        }elseif($action==='execute'){
            $result=(new AiApprovalExecutionService())->execute($approval,$owner);
            MessageUtil::setMessage('Final action completed'.(!empty($result['id'])?' (reference '.$result['id'].')':'').'.');
        }
    }catch(\Throwable $e){MessageUtil::setMessage($e->getMessage(),'Approval workflow','danger');}
    LocationUtils::redirectInternal('panel/agents/approval?id='.$id);
});
$router->run();
