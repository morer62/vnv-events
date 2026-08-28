<?php
use App\Repositories\AiAgentConnectionsRepository;
use App\Repositories\AiAgentsRepository;
use App\Repositories\Connection;
use App\Utils\SiteContext;
use App\Services\AiAgentExecutionService;
use App\Services\AiAgentRegistry;
use App\Services\SocialPublishingService;
use App\Services\LoginService;
use App\Services\AiVideoReelService;
use App\Repositories\AiAgentMediaRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$repo=new AiAgentsRepository();$repo->seed($owner,AiAgentRegistry::definitions());$mode=(string)($_GET['mode']??'social');if(!in_array($mode,['social','carousel','creative','short-video','library'],true))$mode='social';
    $site=SiteContext::siteKey();$db=new Connection();$db->query("SELECT id,title,status,featured_image_url,excerpt,body_html,canonical_url FROM cms_contents WHERE id_owner=:owner AND site_key=:site AND status IN ('GENERATED','PUBLISHED') ORDER BY updated_at DESC LIMIT 100");$db->bind(':owner',$owner);$db->bind(':site',$site);$contents=$db->fetchAll();
    $selectedContentId=max(0,(int)($_GET['content']??0));$selectedContent=null;$contentParagraphs=[];foreach($contents as $content)if((int)$content->id===$selectedContentId){$selectedContent=$content;break;}if($selectedContent){$plain=strip_tags(str_ireplace(['</p>','</h2>','</h3>','</li>'],["\n","\n","\n","\n"],(string)$selectedContent->body_html));$contentParagraphs=array_values(array_filter(array_map(fn($text)=>preg_replace('/\s+/u',' ',trim($text)),preg_split('/\R+/',(string)$plain)?:[]),fn($text)=>mb_strlen($text)>=25));}
    $db->query("SELECT id,title,status,output_url,source_url,source_name,mime_type,transcript_text,transcript_json,subtitles_srt,edit_plan_json,created_at FROM ai_agent_media_jobs WHERE id_owner=:owner AND site_key=:site AND transcript_text IS NOT NULL ORDER BY id DESC LIMIT 100");$db->bind(':owner',$owner);$db->bind(':site',$site);$media=$db->fetchAll();$reelLibrary=[];foreach($media as $item){$plan=json_decode((string)$item->edit_plan_json,true)?:[];$item->project_kind=(string)($plan['_request']['project_kind']??'video');if($item->project_kind==='reel')$reelLibrary[]=$item;}
    $selectedProject=max(0,(int)($_GET['project']??0));$selectedMedia=null;foreach($media as $item)if((int)$item->id===$selectedProject){$selectedMedia=$item;break;}
    $agentKey=['social'=>'social_publisher','carousel'=>'instagram_carousel','creative'=>'instagram_carousel','short-video'=>'short_video','library'=>'social_publisher'][$mode];$agent=$repo->find($owner,$agentKey);$connections=[];$social=$repo->find($owner,'social_publisher');foreach((new AiAgentConnectionsRepository())->all($owner,(int)$social->id) as $item)$connections[$item->platform]=$item;
    $db->query("SELECT p.*,a.agent_key FROM ai_agent_approvals p JOIN ai_agents a ON a.id=p.id_agent WHERE p.id_owner=:owner AND p.site_key=:site AND p.action_type IN ('PUBLISH_CAROUSEL','PUBLISH_SOCIAL_CREATIVE','REVIEW_SHORT_VIDEO') ORDER BY p.id DESC LIMIT 100");$db->bind(':owner',$owner);$db->bind(':site',$site);$creativeLibrary=$db->fetchAll();foreach($creativeLibrary as $creative){$payload=json_decode((string)$creative->payload_json,true)?:[];$creative->preview_url=(string)($payload['image_url']??($payload['slides'][0]['image_url']??''));$creative->preview_caption=mb_substr((string)($payload['caption']??''),0,240);$creative->artwork_status=(string)($payload['image_generation']['status']??'');$creative->artwork_error=(string)($payload['image_generation']['error']??'');}
    return TemplateResponse::render(__DIR__.'/index.twig',['mode'=>$mode,'agent'=>$agent,'contents'=>$contents,'media'=>$media,'reelLibrary'=>$reelLibrary,'selectedProject'=>$selectedProject,'selectedMedia'=>$selectedMedia,'selectedContent'=>$selectedContent,'selectedContentId'=>$selectedContentId,'contentParagraphs'=>$contentParagraphs,'creativeLibrary'=>$creativeLibrary,'connections'=>$connections,'approvals'=>$repo->pendingApprovals($owner,(int)$agent->id)]);
});
$router->post(function(){
    $session=LoginService::getSession();$owner=(int)$session->getOwner();$user=(int)$session->getId();$repo=new AiAgentsRepository();$repo->seed($owner,AiAgentRegistry::definitions());$mode=(string)($_POST['mode']??'social');$action=(string)($_POST['action']??'run');
    try{
        $social=$repo->find($owner,'social_publisher');
        if($action==='archive_creative'||$action==='delete_creative'){
            $db=new Connection();$id=(int)($_POST['approval_id']??0);if($action==='archive_creative'){$db->query("UPDATE ai_agent_approvals SET status='REJECTED',review_note='Archived from Social Media Studio',reviewed_by=:user,reviewed_at=NOW() WHERE id=:id AND id_owner=:owner");$db->bind(':user',$user);}else{$db->query("DELETE FROM ai_agent_approvals WHERE id=:id AND id_owner=:owner");}$db->bind(':id',$id);$db->bind(':owner',$owner);$db->execute();MessageUtil::setMessage($action==='archive_creative'?'Creative archived.':'Creative deleted.');
        }elseif($action==='save_connection'){
            $platform=(string)($_POST['platform']??'');$extra=[];
            if($platform==='facebook')foreach(['app_secret','verify_token'] as $field)if(trim((string)($_POST[$field]??''))!=='')$extra[$field]=trim((string)$_POST[$field]);
            (new AiAgentConnectionsRepository())->save($owner,(int)$social->id,$platform,trim((string)($_POST['account_label']??'')),trim((string)($_POST['account_identifier']??'')),trim((string)($_POST['access_token']??'')),$extra);MessageUtil::setMessage('Social network credentials saved securely.');
        }elseif($action==='verify_connection'){
            $verified=(new SocialPublishingService())->verify($owner,(string)($_POST['platform']??''));
            MessageUtil::setMessage('Connection verified'.(!empty($verified['name'])?': '.$verified['name']:(!empty($verified['username'])?': @'.$verified['username']:'')).'.');
        }elseif($action==='create_reel'){
            $mediaRepo=new AiAgentMediaRepository();$source=$mediaRepo->find($owner,(int)($_POST['media_job_id']??0));if(!$source)throw new RuntimeException('Source video project not found.');
            $parseTime=function(string $value):?float{$value=trim($value);if($value==='')return null;if(is_numeric($value))return max(0,(float)$value);$parts=array_map('floatval',explode(':',$value));return count($parts)===3?$parts[0]*3600+$parts[1]*60+$parts[2]:(count($parts)===2?$parts[0]*60+$parts[1]:null);};
            $service=new AiVideoReelService();$range=$service->range($source,(int)($_POST['target_duration']??30),$parseTime((string)($_POST['range_start']??'')),$parseTime((string)($_POST['range_end']??'')));
            $plan=$service->plan($source,$range,(string)($_POST['caption_style']??'kinetic'),trim((string)($_POST['reel_instructions']??'')));
            $newId=$mediaRepo->createDerivedProject($owner,$user,$source,$source->title.' — Reel '.gmdate('i-s',(int)$range['start']),$plan);
            MessageUtil::setMessage('Reel project #'.$newId.' created from '.gmdate('i:s',(int)$range['start']).' to '.gmdate('i:s',(int)$range['end']).'. Review it before export.');
            LocationUtils::redirectInternal('panel/growth-hub/video-studio?project='.$newId);return;
        }else{
            $key=['social'=>'social_publisher','carousel'=>'instagram_carousel','creative'=>'instagram_carousel','short-video'=>'short_video'][$mode]??'social_publisher';$agent=$repo->find($owner,$key);$slides=[];foreach((array)($_POST['slide_text']??[]) as $index=>$text)if(trim((string)$text)!=='')$slides[]=['page'=>(int)(($_POST['slide_page'][$index]??count($slides)+1)),'text'=>trim((string)$text)];$result=(new AiAgentExecutionService($repo))->run($agent,$owner,$user,'MANUAL',['source_kind'=>(string)($_POST['source_kind']??'content'),'content_id'=>(int)($_POST['content_id']??0),'media_job_id'=>(int)($_POST['media_job_id']??0),'networks'=>array_values(array_intersect(['facebook','instagram','linkedin','youtube'],(array)($_POST['networks']??[]))),'generate_images'=>!empty($_POST['generate_images']),'image_provider'=>'openai','selected_slides'=>$slides,'creative_format'=>(string)($_POST['creative_format']??($mode==='creative'?'single-square':'carousel-square')),'concept'=>trim((string)($_POST['concept']??'')),'reference_urls'=>trim((string)($_POST['reference_urls']??'')),'caption_link'=>trim((string)($_POST['caption_link']??'')),'include_link'=>!empty($_POST['include_link']),'reel_instructions'=>trim((string)($_POST['reel_instructions']??'')),'caption_style'=>(string)($_POST['caption_style']??'kinetic'),'target_duration'=>(int)($_POST['target_duration']??30)]);
            MessageUtil::setMessage('Creative run #'.$result['run_id'].' is ready with '.$result['output']['approval_count'].' approval(s).');
        }
    }catch(\Throwable $e){MessageUtil::setMessage($e->getMessage(),'Distribution Studio','danger');}
    LocationUtils::redirectInternal('panel/growth-hub/distribution?mode='.rawurlencode($mode));
});
$router->run();
