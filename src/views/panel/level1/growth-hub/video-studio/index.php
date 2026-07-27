<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoTranscriptionService;
use App\Services\AiVideoPlanningService;
use App\Services\AiVideoRenderService;
use App\Services\AiVideoIngestService;
use App\Services\AiCaptionEditorService;
use App\Services\AiProviderImageService;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router=new Router();
$router->get(function(){
    $session=LoginService::getSession(); $repo=new AiAgentMediaRepository();
    $owner=(int)$session->getOwner();$jobs=$repo->all($owner);foreach($jobs as $job)$job->revisions=$repo->revisions($owner,(int)$job->id);
    $ingest=new AiVideoIngestService();
    return TemplateResponse::render(__DIR__.'/index.twig',[
        'jobs'=>$jobs,'assets'=>$repo->assets($owner),'renderReady'=>(new AiVideoRenderService())->available(),
        'ingestRoot'=>$ingest->ownerRoot($owner),'ingestFolders'=>$ingest->folders($owner),'ingestStableSeconds'=>$ingest->stableSeconds(),
    ]);
});
$router->post(function(){
    $session=LoginService::getSession(); $owner=(int)$session->getOwner(); $repo=new AiAgentMediaRepository();
    try{
        $action=(string)($_POST['action']??'upload');
        if($action==='import_server_folder'){
            $ingest=new AiVideoIngestService();$files=$ingest->importable($owner,trim((string)($_POST['folder_name']??'')));$imported=0;$skipped=0;
            foreach($files as $file){
                if($repo->sourceExists($owner,(string)$file['source_url'])){$skipped++;continue;}
                $repo->add($owner,(int)$session->getId(),$file);$imported++;
            }
            if(!$imported)throw new RuntimeException($skipped.' file(s) were already imported from this folder.');
            MessageUtil::setMessage($imported.' server video(s) imported as private projects'.($skipped?' ('.$skipped.' already existed).':'.'));
        }elseif($action==='upload_asset'){
            if(!FileUtils::hasFile($_FILES,'asset'))throw new RuntimeException('Select a production asset.');
            $file=$_FILES['asset'];$allowed=['image/png','image/jpeg','image/webp','video/mp4','video/quicktime','audio/mpeg','audio/wav'];
            if(!in_array((string)$file['type'],$allowed,true))throw new RuntimeException('Unsupported production asset format.');
            $type=(string)($_POST['asset_type']??'LOGO');$url=FileUtils::saveFile($file,'agents/video-studio/assets');
            $repo->addAsset($owner,(int)$session->getId(),$type,trim((string)($_POST['asset_name']??''))?:pathinfo((string)$file['name'],PATHINFO_FILENAME),$url,(string)$file['type']);
            MessageUtil::setMessage('Reusable production asset uploaded.');
        }elseif($action==='duplicate_project'){
            $newId=$repo->duplicate($owner,(int)$session->getId(),(int)($_POST['id']??0));if(!$newId)throw new RuntimeException('Project not found.');MessageUtil::setMessage('Reusable remix project #'.$newId.' created.');
        }elseif($action==='clean_captions'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Project not found.');
            $phrases=preg_split('/[\r\n,;]+/',(string)($_POST['remove_phrases']??''));$selected=trim((string)($_POST['selected_text']??''));if($selected!=='')$phrases[]=$selected;
            $repo->revision($owner,(int)$session->getId(),$job,'CAPTION_CLEANUP','Before removing phrases');
            $result=(new AiCaptionEditorService())->remove((string)$job->transcript_text,(string)$job->subtitles_srt,$phrases,json_decode((string)$job->edit_plan_json,true)?:[]);
            $repo->updateEditor($owner,$id,$result['transcript'],$result['srt'],$result['plan']);MessageUtil::setMessage(count($result['removed_segments']).' caption/video segment(s) marked for removal.');
        }elseif($action==='transcribe'){
            $id=(int)($_POST['id']??0); $job=$repo->find($owner,$id);
            if(!$job) throw new RuntimeException('Media job not found.');
            try{$repo->updateTranscript($owner,$id,(new AiVideoTranscriptionService())->transcribe((string)$job->source_url));}
            catch(\Throwable $e){$repo->fail($owner,$id,$e->getMessage()); throw $e;}
            MessageUtil::setMessage('Transcription and subtitles are ready.');
        }elseif($action==='queue_render'){
            $id=(int)($_POST['id']??0); $job=$repo->find($owner,$id);
            if(!$job) throw new RuntimeException('Media job not found.');
            if(!(new AiVideoRenderService())->available()) throw new RuntimeException('FFmpeg is not configured on this server.');
            $repo->queueRender($owner,$id);
            MessageUtil::setMessage('Final render queued. The downloadable MP4 will appear when the worker finishes.');
        }elseif($action==='save_editor' || $action==='generate_plan'){
            $id=(int)($_POST['id']??0); $job=$repo->find($owner,$id);
            if(!$job) throw new RuntimeException('Media job not found.');
            $transcript=trim((string)($_POST['transcript_text']??''));
            $subtitles=trim((string)($_POST['subtitles_srt']??''));
            $repo->revision($owner,(int)$session->getId(),$job,$action==='generate_plan'?'AI_PLAN':'MANUAL_EDIT','Automatic version before editor save');
            $instructions=trim((string)($_POST['instructions']??''));$props=trim((string)($_POST['visual_insert_instructions']??''));if($props!=='')$instructions.="\nTimed visual inserts requested:\n".$props;
            $aspect=(string)($_POST['aspect_ratio']??'9:16');if(!in_array($aspect,['9:16','16:9','16:9_4k','1:1','4:5'],true))$aspect='9:16';
            $instructions.="\nMotion direction: ".trim((string)($_POST['motion_direction']??'AI decides purposeful moments only')).". Motion intensity: ".trim((string)($_POST['motion_intensity']??'medium')).".";
            $plan=$action==='generate_plan' ? (new AiVideoPlanningService())->createPlan($owner,(string)($_POST['provider']??'openai'),$transcript,$instructions,$aspect,(string)($_POST['caption_style']??'clean'),trim((string)($_POST['logo_url']??'')),(string)($_POST['style_preset']??'vnv_premium'),[
                'intro_url'=>trim((string)($_POST['intro_url']??'')),'outro_url'=>trim((string)($_POST['outro_url']??'')),
                'overlay_url'=>trim((string)($_POST['overlay_url']??'')),'audio_url'=>trim((string)($_POST['audio_url']??'')),
            ]) : null;
            if($plan){$layout=json_decode((string)($_POST['overlay_layout_json']??''),true);$plan['_request']['overlay_layout']=is_array($layout)?array_slice($layout,0,30):[];}
            if($plan&&!empty($_POST['generate_visual_inserts'])){
                $imageProvider=in_array($_POST['insert_provider']??'', ['openai','gemini'],true)?$_POST['insert_provider']:'openai';
                foreach(array_slice((array)($plan['generated_inserts']??[]),0,3,true) as $index=>$insert){if(trim((string)($insert['prompt']??''))==='')continue;
                    $generated=(new AiProviderImageService())->generate($owner,$imageProvider,(string)$insert['prompt'],(string)($_POST['aspect_ratio']??'9:16'));
                    $plan['generated_inserts'][$index]['asset_url']=$generated['url'];$plan['generated_inserts'][$index]['media_type']='generated_image';$plan['generated_inserts'][$index]['status']='READY';
                }
            }
            $repo->updateEditor($owner,$id,$transcript,$subtitles,$plan);
            MessageUtil::setMessage($action==='generate_plan' ? 'The AI edit plan is ready for review.' : 'Transcript and subtitles saved.');
        }else{
            if(!FileUtils::hasFile($_FILES,'media')) throw new RuntimeException('Select a video or audio file.');
            $file=$_FILES['media']; $allowed=['video/mp4','video/quicktime','video/webm','audio/mpeg','audio/mp4','audio/wav','audio/x-wav'];
            if(!in_array((string)$file['type'],$allowed,true)) throw new RuntimeException('Supported formats: MP4, MOV, WebM, MP3, M4A and WAV.');
            if((int)$file['size']>250*1024*1024) throw new RuntimeException('The maximum upload size is 250 MB.');
            $url=FileUtils::saveFile($file,'agents/video-studio');
            $repo->add($owner,(int)$session->getId(),['title'=>trim((string)($_POST['title']??'')) ?: pathinfo((string)$file['name'],PATHINFO_FILENAME),'source_url'=>$url,'source_name'=>$file['name'],'mime_type'=>$file['type']]);
            MessageUtil::setMessage('Media uploaded. You can now generate its transcription and subtitles.');
        }
    }catch(\Throwable $e){MessageUtil::setMessage($e->getMessage(),'Video Studio','danger');}
    LocationUtils::redirectInternal('panel/growth-hub/video-studio');
});
$router->run();
