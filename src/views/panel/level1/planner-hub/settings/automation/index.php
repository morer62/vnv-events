<?php

use App\Services\AutomationCenterService;
use App\Services\MochiConciergeService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$redirect=static function(): never {LocationUtils::redirectInternal('panel/planner-hub/settings/automation');};

$router->get(function(){
    $user=LoginService::getSession();
    $owner=(int)((int)$user->getLevel()===1?$user->getId():$user->getOwner());
    try{$service=new AutomationCenterService($owner);if((int)$user->getLevel()!==1&&!$service->isReviewer((int)$user->getId())){http_response_code(403);return 'Automation reviewer access required.';}$dashboard=$service->dashboard();return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>$service->settings(),'counts'=>$dashboard['counts'],'runs'=>$dashboard['runs'],'outbox'=>$dashboard['outbox'],'recommendations'=>$service->pendingReview(),'mochiConversation'=>(new MochiConciergeService($owner))->recent((int)$user->getId()),'isAdmin'=>(int)$user->getLevel()===1,'message'=>MessageUtil::getMessage()]);}
    catch(Throwable $e){return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>null,'counts'=>[],'runs'=>[],'outbox'=>[],'schemaError'=>$e->getMessage(),'message'=>MessageUtil::getMessage()]);}
});

$router->post(function()use($redirect){
    $user=LoginService::getSession();$owner=(int)((int)$user->getLevel()===1?$user->getId():$user->getOwner());
    $isAjax=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';
    try{
        $service=new AutomationCenterService($owner);$isAdmin=(int)$user->getLevel()===1;$isReviewer=$service->isReviewer((int)$user->getId());if(!$isAdmin&&!$isReviewer)throw new RuntimeException('Automation reviewer access required.');$action=(string)($_POST['action']??'save');
        if($action==='save'){if(!$isAdmin)throw new RuntimeException('Only Level 1 can change automation settings.');$service->saveSettings($_POST,(int)$user->getId());MessageUtil::setMessage('Automation settings saved.','Automation Center','success');}
        elseif($action==='run'){$result=$service->runScheduler('MANUAL');MessageUtil::setMessage('Scheduler completed: '.json_encode($result),'Automation Center','success');}
        elseif($action==='deliver'){$result=$service->processOutbox(30);MessageUtil::setMessage('Delivery worker completed: '.json_encode($result),'Automation Center','success');}
        elseif($action==='test'){$email=trim((string)($_POST['test_email']??''));$service->queueTest($email,(int)$user->getId());MessageUtil::setMessage('Controlled test queued for '.$email.'. Run delivery to send it.','Automation Center','success');}
        elseif($action==='review'){$service->review((string)($_POST['dedupe_key']??''),(string)($_POST['decision']??''),(int)$user->getId(),trim((string)($_POST['comment']??'')),($_POST['memory_type']??null),trim((string)($_POST['memory']??'')));MessageUtil::setMessage('Recomendación revisada. La conversación y el contexto quedaron guardados.','Mochi','success');}
        elseif($action==='ask_mochi'){$reply=(new MochiConciergeService($owner))->ask((int)$user->getId(),(string)($_POST['message']??''));if($isAjax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'reply'=>$reply],JSON_UNESCAPED_UNICODE);exit;}MessageUtil::setMessage('Mochi: '.$reply,'Mochi','success');}
    }catch(Throwable $e){if($isAjax){header('Content-Type: application/json; charset=utf-8');http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}MessageUtil::setMessage($e->getMessage(),'Automation Center','danger');}
    $redirect();
});
$router->run();
