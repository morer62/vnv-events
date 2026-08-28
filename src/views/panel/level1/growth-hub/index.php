<?php
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use App\Utils\SiteContext;
use App\Services\AiAgentRegistry;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$repo=new AiAgentsRepository();$repo->seed($owner,AiAgentRegistry::definitions());
    $productionKeys=['blog_writer','video_studio','social_publisher','instagram_carousel','short_video'];
    $agents=[];foreach($repo->allForOwner($owner) as $agent)if(in_array($agent->agent_key,$productionKeys,true))$agents[$agent->agent_key]=$agent;
    $approvals=array_values(array_filter($repo->pendingApprovals($owner),fn($item)=>$item->status==='PENDING'&&in_array($item->agent_key??'', $productionKeys,true)));
    $siteKey=SiteContext::siteKey();$db=new Connection();$db->query("SELECT COUNT(*) total,SUM(status='GENERATED') generated,SUM(status='PUBLISHED') published FROM cms_contents WHERE id_owner=:owner AND site_key=:site");$db->bind(':owner',$owner);$db->bind(':site',$siteKey);$content=$db->fetchOne();
    $db->query("SELECT COUNT(*) total,SUM(status IN ('READY','COMPLETED')) ready,SUM(status IN ('QUEUED','RENDERING')) processing FROM ai_agent_media_jobs WHERE id_owner=:owner AND site_key=:site");$db->bind(':owner',$owner);$db->bind(':site',$siteKey);$media=$db->fetchOne();
    return TemplateResponse::render(__DIR__.'/index.twig',['agents'=>$agents,'approvals'=>$approvals,'content'=>$content,'media'=>$media]);
});
$router->run();
