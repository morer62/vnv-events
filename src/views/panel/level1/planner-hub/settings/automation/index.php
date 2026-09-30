<?php

use App\Services\AutomationCenterService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$redirect=static function(): never {LocationUtils::redirectInternal('panel/planner-hub/settings/automation');};

$router->get(function(){
    $user=LoginService::getSession();
    if((int)$user->getLevel()!==1){http_response_code(403);return 'Level 1 access required.';}
    try{$service=new AutomationCenterService((int)$user->getId());$dashboard=$service->dashboard();return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>$service->settings(),'counts'=>$dashboard['counts'],'runs'=>$dashboard['runs'],'outbox'=>$dashboard['outbox'],'message'=>MessageUtil::getMessage()]);}
    catch(Throwable $e){return TemplateResponse::render(__DIR__.'/index.twig',['settings'=>null,'counts'=>[],'runs'=>[],'outbox'=>[],'schemaError'=>$e->getMessage(),'message'=>MessageUtil::getMessage()]);}
});

$router->post(function()use($redirect){
    $user=LoginService::getSession();if((int)$user->getLevel()!==1){http_response_code(403);return;}
    try{
        $service=new AutomationCenterService((int)$user->getId());$action=(string)($_POST['action']??'save');
        if($action==='save'){$service->saveSettings($_POST,(int)$user->getId());MessageUtil::setMessage('Automation settings saved.','Automation Center','success');}
        elseif($action==='run'){$result=$service->runScheduler('MANUAL');MessageUtil::setMessage('Scheduler completed: '.json_encode($result),'Automation Center','success');}
        elseif($action==='deliver'){$result=$service->processOutbox(30);MessageUtil::setMessage('Delivery worker completed: '.json_encode($result),'Automation Center','success');}
        elseif($action==='test'){$email=trim((string)($_POST['test_email']??''));$service->queueTest($email,(int)$user->getId());MessageUtil::setMessage('Controlled test queued for '.$email.'. Run delivery to send it.','Automation Center','success');}
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Automation Center','danger');}
    $redirect();
});
$router->run();
