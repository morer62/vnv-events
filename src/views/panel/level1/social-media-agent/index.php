<?php

use App\Repositories\Connection;
use App\Repositories\AiAgentConnectionsRepository;
use App\Services\LoginService;
use App\Services\SocialEditorialAgentService;
use App\Services\SocialPublishingService;
use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $db=new Connection();$db->query("SELECT * FROM social_editorial_runs ORDER BY id DESC LIMIT 20");$runs=$db->fetchAll();
    $connections=[];$db->query("SELECT c.site_key,c.platform,c.account_label,c.account_identifier,c.status,c.verified_at,c.last_error FROM ai_agent_connections c JOIN ai_agents a ON a.id=c.id_agent WHERE a.agent_key='social_publisher' AND c.site_key IN ('miamitechlab','vnvevents') AND c.platform IN ('facebook','linkedin') ORDER BY c.site_key,c.platform");foreach($db->fetchAll() as $row)$connections[$row->site_key][$row->platform]=$row;
    return TemplateResponse::render(__DIR__.'/index.twig',['runs'=>$runs,'publications'=>(new SocialEditorialAgentService())->history(200),'connections'=>$connections]);
});
$router->post(function(){
    CSRF::validateCSRF();$user=LoginService::getSession();$action=(string)($_POST['action']??'');$db=new Connection();
    try{
        if($action==='run'){$result=(new SocialEditorialAgentService())->runAll((string)($_POST['mode']??'REVIEW_BEFORE_PUBLISH'),(int)$user->getId());MessageUtil::setMessage('Social Media Agent run #'.$result['run_id'].' completed with status '.$result['status'].'.');}
        elseif($action==='save_connection'){$site=(string)($_POST['site_key']??'');$platform=(string)($_POST['platform']??'');if(!in_array($site,['miamitechlab','vnvevents'],true)||!in_array($platform,['facebook','linkedin'],true))throw new RuntimeException('Invalid brand or social network.');$db->query("SELECT id FROM ai_agents WHERE id_owner=2 AND site_key=:site AND agent_key='social_publisher' LIMIT 1");$db->bind(':site',$site);$agent=$db->fetchOne();if(!$agent)throw new RuntimeException('Run the Social Media Agent SQL before connecting accounts.');(new AiAgentConnectionsRepository())->save(2,(int)$agent->id,$platform,trim((string)($_POST['account_label']??'')),trim((string)($_POST['account_identifier']??'')),trim((string)($_POST['access_token']??'')),[],$site);MessageUtil::setMessage('Credentials encrypted and saved. Use Verify before scheduling.');}
        elseif($action==='verify_connection'){$site=(string)($_POST['site_key']??'');$platform=(string)($_POST['platform']??'');if(!in_array($site,['miamitechlab','vnvevents'],true)||!in_array($platform,['facebook','linkedin'],true))throw new RuntimeException('Invalid brand or social network.');$verified=(new SocialPublishingService(null,$site))->verify(2,$platform);MessageUtil::setMessage('Verified official Page connection'.(!empty($verified['name'])?': '.$verified['name']:'').'.');}
        elseif(in_array($action,['schedule','skip'],true)){$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['publication_ids']??[])))));if(!$ids)throw new RuntimeException('Select at least one publication.');$done=0;foreach($ids as $id){if($action==='schedule'){$db->query("SELECT p.id FROM social_editorial_publications p JOIN ai_agents a ON a.id_owner=2 AND a.site_key=p.site_key AND a.agent_key='social_publisher' JOIN ai_agent_connections c ON c.id_agent=a.id AND c.id_owner=2 AND c.site_key=p.site_key AND c.platform=p.network AND c.status='VERIFIED' WHERE p.id=:id AND p.state='PREPARED' LIMIT 1");$db->bind(':id',$id);if(!$db->fetchOne())continue;}$db->query("UPDATE social_editorial_publications SET state=:state WHERE id=:id AND state='PREPARED'");$db->bind(':state',$action==='schedule'?'SCHEDULED':'SKIPPED');$db->bind(':id',$id);$db->execute();$done+=$db->rowCount();}if(!$done&&$action==='schedule')throw new RuntimeException('Nothing was scheduled: verify the official Page connection for each selected brand and network first.');MessageUtil::setMessage($done.' publication(s) marked '.($action==='schedule'?'SCHEDULED':'SKIPPED').'.');}
        else throw new RuntimeException('Unsupported Social Media Agent action.');
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Social Media Agent','danger');}
    LocationUtils::redirectInternal('panel/social-media-agent');
});
$router->run();
