<?php
use App\Repositories\AiAgentConnectionsRepository;
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use App\Services\AiAgentExecutionService;
use App\Services\AiAgentRegistry;
use App\Services\SocialPublishingService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$repo=new AiAgentsRepository();$repo->seed($owner,AiAgentRegistry::definitions());$mode=(string)($_GET['mode']??'social');if(!in_array($mode,['social','carousel','short-video'],true))$mode='social';
    $db=new Connection();$db->query("SELECT id,title,status,featured_image_url FROM cms_contents WHERE id_owner=:owner AND status IN ('GENERATED','PUBLISHED') ORDER BY updated_at DESC LIMIT 100");$db->bind(':owner',$owner);$contents=$db->fetchAll();
    $db->query("SELECT id,title,status,output_url,created_at FROM ai_agent_media_jobs WHERE id_owner=:owner AND status='COMPLETED' AND output_url IS NOT NULL ORDER BY id DESC LIMIT 100");$db->bind(':owner',$owner);$media=$db->fetchAll();
    $selectedProject=max(0,(int)($_GET['project']??0));$selectedMedia=null;foreach($media as $item)if((int)$item->id===$selectedProject){$selectedMedia=$item;break;}
    $agentKey=['social'=>'social_publisher','carousel'=>'instagram_carousel','short-video'=>'short_video'][$mode];$agent=$repo->find($owner,$agentKey);$connections=[];$social=$repo->find($owner,'social_publisher');foreach((new AiAgentConnectionsRepository())->all($owner,(int)$social->id) as $item)$connections[$item->platform]=$item;
    return TemplateResponse::render(__DIR__.'/index.twig',['mode'=>$mode,'agent'=>$agent,'contents'=>$contents,'media'=>$media,'selectedProject'=>$selectedProject,'selectedMedia'=>$selectedMedia,'connections'=>$connections,'approvals'=>$repo->pendingApprovals($owner,(int)$agent->id)]);
});
$router->post(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$user=(int)$session->getId();$repo=new AiAgentsRepository();$repo->seed($owner,AiAgentRegistry::definitions());$mode=(string)($_POST['mode']??'social');$action=(string)($_POST['action']??'run');
    try{
        $social=$repo->find($owner,'social_publisher');
        if($action==='save_connection'){
            $platform=(string)($_POST['platform']??'');$extra=[];
            if($platform==='facebook')foreach(['app_secret','verify_token'] as $field)if(trim((string)($_POST[$field]??''))!=='')$extra[$field]=trim((string)$_POST[$field]);
            (new AiAgentConnectionsRepository())->save($owner,(int)$social->id,$platform,trim((string)($_POST['account_label']??'')),trim((string)($_POST['account_identifier']??'')),trim((string)($_POST['access_token']??'')),$extra);MessageUtil::setMessage('Social network credentials saved securely.');
        }elseif($action==='verify_connection'){
            $verified=(new SocialPublishingService())->verify($owner,(string)($_POST['platform']??''));
            MessageUtil::setMessage('Connection verified'.(!empty($verified['name'])?': '.$verified['name']:(!empty($verified['username'])?': @'.$verified['username']:'')).'.');
        }else{
            $key=['social'=>'social_publisher','carousel'=>'instagram_carousel','short-video'=>'short_video'][$mode]??'social_publisher';$agent=$repo->find($owner,$key);$result=(new AiAgentExecutionService($repo))->run($agent,$owner,$user,'MANUAL',['source_kind'=>(string)($_POST['source_kind']??'content'),'content_id'=>(int)($_POST['content_id']??0),'media_job_id'=>(int)($_POST['media_job_id']??0),'networks'=>array_values(array_intersect(['facebook','instagram','linkedin','youtube'],(array)($_POST['networks']??[]))),'generate_images'=>!empty($_POST['generate_images']),'image_provider'=>'openai','reel_instructions'=>trim((string)($_POST['reel_instructions']??'')),'caption_style'=>(string)($_POST['caption_style']??'kinetic'),'target_duration'=>(int)($_POST['target_duration']??30)]);
            MessageUtil::setMessage('Creative run #'.$result['run_id'].' is ready with '.$result['output']['approval_count'].' approval(s).');
        }
    }catch(\Throwable $e){MessageUtil::setMessage($e->getMessage(),'Distribution Studio','danger');}
    LocationUtils::redirectInternal('panel/growth-hub/distribution?mode='.rawurlencode($mode));
});
$router->run();
