<?php
use App\Services\AutomationCenterService;
use App\Services\MochiConciergeService;
use App\Services\MochiImageReaderService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();$redirect=static function(): never {LocationUtils::redirectInternal('panel/planner-hub/settings/automation');};
$context=static function(){$user=LoginService::getSession();if(!$user)throw new RuntimeException('Authentication required.');$level=(int)$user->getLevel();if(!in_array($level,[1,4,5],true))throw new RuntimeException('Mochi is not available for this user level.');$owner=(int)($level===1?$user->getId():$user->getOwner());return [$user,$owner,$level];};

$router->get(function()use($context){
    try{[$user,$owner,$level]=$context();$automation=new AutomationCenterService($owner);$reviewer=$level===1||$automation->isReviewer((int)$user->getId());$concierge=new MochiConciergeService($owner);$userId=(int)$user->getId();$mochi=isset($_GET['session'])?$concierge->open($userId,$level,(int)$_GET['session']):(isset($_GET['date'])?$concierge->openDate($userId,$level,(string)$_GET['date']):$concierge->openLatest($userId,$level));$view=['mochi'=>$mochi,'isAdmin'=>$level===1,'isReviewer'=>$reviewer,'userLevel'=>$level,'message'=>MessageUtil::getMessage(),'settings'=>null,'counts'=>[],'runs'=>[],'outbox'=>[],'recommendations'=>[]];return TemplateResponse::render(__DIR__.'/index.twig',$view);}
    catch(Throwable $e){http_response_code(422);return TemplateResponse::render(__DIR__.'/index.twig',['schemaError'=>$e->getMessage(),'message'=>MessageUtil::getMessage()]);}
});

$router->post(function()use($context,$redirect){
    $isAjax=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';
    try{[$user,$owner,$level]=$context();$userId=(int)$user->getId();$service=new AutomationCenterService($owner);$isAdmin=$level===1;$reviewer=$isAdmin||$service->isReviewer($userId);$action=(string)($_POST['action']??'');$mochi=new MochiConciergeService($owner);
        if($action==='ask_mochi'){
            $message=trim((string)($_POST['message']??''));
            $imageTexts=!empty($_FILES['screenshots'])?(new MochiImageReaderService())->extractMany($_FILES['screenshots']):[];
            if(!$imageTexts&&!empty($_FILES['screenshot']))$imageTexts=(new MochiImageReaderService())->extractMany($_FILES['screenshot']);
            if($imageTexts){$blocks=[];$remaining=max(0,5800-mb_strlen($message));foreach($imageTexts as $index=>$text){$header='[Captura '.($index+1).' procesada por el modelo multimodal]' . "\n";$allowance=max(0,$remaining-mb_strlen($header)-2);if($allowance<80)break;$value=mb_substr((string)$text,0,$allowance);$blocks[]=$header.$value;$remaining-=mb_strlen($header.$value)+2;}if(!$blocks)throw new RuntimeException('El texto ya es demasiado largo para agregar capturas. Envía las capturas en un mensaje separado.');$message=trim($message."\n\n".implode("\n\n",$blocks));}
            $result=$mochi->ask($userId,$level,$message,!empty($_POST['session_id'])?(int)$_POST['session_id']:null);
            if($imageTexts){$result['image_read']=true;$result['images_read']=count($imageTexts);}
        }
        elseif($action==='confirm_mochi_action')$result=$mochi->confirmAction($userId,$level,(int)($_POST['session_id']??0),(int)($_POST['draft_id']??0));
        elseif($action==='cancel_mochi_action')$result=$mochi->cancelAction($userId,(int)($_POST['session_id']??0),(int)($_POST['draft_id']??0));
        elseif($action==='new_mochi_chat')$result=$mochi->newChat($userId,$level);
        elseif($action==='open_mochi_session')$result=$mochi->open($userId,$level,(int)($_POST['session_id']??0));
        elseif(!$isAdmin&&!$reviewer)throw new RuntimeException('Automation reviewer access required.');
        elseif($action==='save'){$service->saveSettings($_POST,$userId);$result=['message'=>'Automation settings saved.'];}
        elseif(in_array($action,['run','deliver','test'],true)){if(!$isAdmin)throw new RuntimeException('Only Level 1 can run automation jobs or tests.');$result=match($action){'run'=>$service->runScheduler('MANUAL'),'deliver'=>$service->processOutbox(30),'test'=>($service->queueTest(trim((string)($_POST['test_email']??'')),$userId)??['queued'=>true])};}
        elseif($action==='review'){$service->review((string)($_POST['dedupe_key']??''),(string)($_POST['decision']??''),$userId,trim((string)($_POST['comment']??'')),($_POST['memory_type']??null),trim((string)($_POST['memory']??'')));$result=['message'=>'Recomendación revisada.'];}
        else throw new RuntimeException('Invalid action.');
        if($isAjax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+(is_array($result)?$result:['result'=>$result]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}MessageUtil::setMessage((string)($result['message']??'Acción completada.'),'Mochi','success');
    }catch(Throwable $e){if($isAjax){header('Content-Type: application/json; charset=utf-8');http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}MessageUtil::setMessage($e->getMessage(),'Mochi','danger');}
    $redirect();
});
$router->run();
