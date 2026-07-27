<?php
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use App\Services\AiAgentRegistry;
use App\Services\AiModelGateway;
use App\Services\AiTrendService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $s=LoginService::getSession();$db=new Connection();$db->query("SELECT * FROM ai_agent_editorial_plans WHERE id_owner=:owner ORDER BY id LIMIT 1");$db->bind(':owner',(int)$s->getOwner());$plan=$db->fetchOne();
    return TemplateResponse::render(__DIR__.'/index.twig',['plan'=>$plan]);
});
$router->post(function(){
    $s=LoginService::getSession();$owner=(int)$s->getOwner();$user=(int)$s->getId();$db=new Connection();
    try{
        $values=['articles'=>max(0,min(25,(int)($_POST['articles_per_week']??1))),'locations'=>max(0,min(50,(int)($_POST['location_pages_per_week']??0))),'pages'=>max(0,min(25,(int)($_POST['pages_per_week']??0))),'social'=>max(0,min(50,(int)($_POST['social_posts_per_week']??3))),'videos'=>max(0,min(25,(int)($_POST['video_posts_per_week']??1))),'instructions'=>trim((string)($_POST['instructions']??''))];
        $db->query("INSERT INTO ai_agent_editorial_plans(id_owner,id_user,articles_per_week,location_pages_per_week,pages_per_week,social_posts_per_week,video_posts_per_week,instructions)
          VALUES(:owner,:user,:articles,:locations,:pages,:social,:videos,:instructions)
          ON DUPLICATE KEY UPDATE id_user=VALUES(id_user),articles_per_week=VALUES(articles_per_week),location_pages_per_week=VALUES(location_pages_per_week),pages_per_week=VALUES(pages_per_week),social_posts_per_week=VALUES(social_posts_per_week),video_posts_per_week=VALUES(video_posts_per_week),instructions=VALUES(instructions)");
        foreach(['owner'=>$owner,'user'=>$user]+$values as $k=>$v)$db->bind(':'.$k,$v);$db->execute();
        if(($_POST['action']??'save')==='generate'){
            $db->query("SELECT id,title,content_type,status,updated_at FROM cms_contents WHERE id_owner=:owner ORDER BY updated_at DESC LIMIT 100");$db->bind(':owner',$owner);$library=$db->fetchAll();
            $result=(new AiModelGateway())->json($owner,'openai','You are the VNV Events editorial director. Create a practical seven-day plan based on quotas, existing content, services and current format signals. Never publish. Return JSON only.',['quotas'=>$values,'library'=>$library,'video_signals'=>(new AiTrendService())->currentVideoSignals()],['week_summary'=>'','items'=>[['day'=>'','content_type'=>'article|location|page|social|video','title'=>'','angle'=>'','source_or_service'=>'','networks'=>[],'approval_required'=>true]]]);
            $repo=new AiAgentsRepository();$repo->seed($owner,AiAgentRegistry::definitions());$agent=$repo->find($owner,'content_refresh');$run=$repo->createRun((int)$agent->id,$owner,'MANUAL',$user,$values);$repo->finishRun($run,'AWAITING_APPROVAL',$result);$id=$repo->createApproval($run,(int)$agent->id,$owner,'REVIEW_EDITORIAL_PLAN','Review weekly Growth Hub plan',$result,$user);
            $db->query("UPDATE ai_agent_editorial_plans SET last_planned_at=NOW() WHERE id_owner=:owner");$db->bind(':owner',$owner);$db->execute();MessageUtil::setMessage('Weekly plan generated for approval.');LocationUtils::redirectInternal('panel/agents/approval?id='.$id);
        }
        MessageUtil::setMessage('Editorial automation settings saved.');
    }catch(Throwable $e){MessageUtil::setMessage($e->getMessage(),'Editorial settings','danger');}
    LocationUtils::redirectInternal('panel/growth-hub/settings');
});
$router->run();
