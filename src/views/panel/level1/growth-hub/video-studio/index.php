<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoTranscriptionService;
use App\Services\AiVideoPlanningService;
use App\Services\AiVideoRenderService;
use App\Services\AiVideoIngestService;
use App\Services\AiTranscriptTimelineService;
use App\Services\AiCaptionStyleRegistry;
use App\Services\AiVideoProxyService;
use App\Services\AiReusableMediaService;
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
    $ingest=new AiVideoIngestService();
    $owner=(int)$session->getOwner();$jobs=$repo->all($owner);$projectId=max(0,(int)($_GET['project']??0));$selected=null;$ingestRoot='';$ingestFolders=[];$ingestError='';
    try{
        if(!$projectId){$ingestRoot=$ingest->ownerRoot($owner);$ingestFolders=$ingest->folders($owner);}
        foreach($jobs as $job)$job->project_files=$ingest->inventoryForSource($owner,(string)$job->source_url);
    }catch(\Throwable $e){
        $ingestError=$e->getMessage();
        foreach($jobs as $job)$job->project_files=[];
    }
    foreach($jobs as $job){$job->revisions=$repo->revisions($owner,(int)$job->id);$jobPlan=json_decode((string)$job->edit_plan_json,true);$jobRequest=is_array($jobPlan)?(array)($jobPlan['_request']??[]):[];$job->project_kind=(string)($jobRequest['project_kind']??'long');$job->parent_project_id=(int)($jobRequest['parent_project_id']??0);}
    if($projectId){
        foreach($jobs as $job)if((int)$job->id===$projectId){$selected=$job;break;}
        if(!$selected)throw new RuntimeException('Video project not found.');
        $selectedPlan=json_decode((string)$selected->edit_plan_json,true);
        $selectedRequest=is_array($selectedPlan)?(array)($selectedPlan['_request']??[]):[];
        $selected->caption_preset=AiCaptionStyleRegistry::find((string)($selectedRequest['caption_preset']??'classic-bold'))['id'];
        $selected->caption_size_percent=max(35,min(140,(int)($selectedRequest['caption_size_percent']??75)));
        $selected->project_kind=(string)($selectedRequest['project_kind']??'long');
        $selected->parent_project_id=(int)($selectedRequest['parent_project_id']??0);
        $selected->reel_range=(array)($selectedRequest['reel_range']??[]);
        $selected->preview_removed_segments=array_values(array_filter((array)($selectedRequest['removed_segments']??[]),static fn($segment)=>is_array($segment)&&isset($segment['start'],$segment['end'])));
        $selected->precision_edits=array_values(array_filter((array)($selectedRequest['precision_edits']??[]),static fn($segment)=>is_array($segment)&&isset($segment['start'],$segment['end'])));
        $selected->speaker_profiles=array_values(array_filter((array)($selectedRequest['speaker_profiles']??[]),static fn($speaker)=>is_array($speaker)&&trim((string)($speaker['name']??''))!==''));
        $selected->reference_script=(string)($selectedRequest['reference_script']??'');
        $proxyService=new AiVideoProxyService();
        $selected->proxy_ready=$proxyService->exists($owner,(int)$selected->id);
        $selected->proxy_version=$proxyService->version($owner,(int)$selected->id);
    }
    return TemplateResponse::render(__DIR__.($selected?'/editor.twig':'/library.twig'),[
        'jobs'=>$jobs,'assets'=>$repo->assets($owner),'renderReady'=>(new AiVideoRenderService())->available(),
        'selected'=>$selected,'timeline'=>$selected?(new AiTranscriptTimelineService())->timeline($selected):['blocks'=>[],'pauses'=>[],'has_word_timestamps'=>false],
        'captionStyles'=>AiCaptionStyleRegistry::all(),
        'browserUploadMaxGb'=>max(1,min(50,(int)($_ENV['VIDEO_BROWSER_UPLOAD_MAX_GB']??12))),
        'ingestRoot'=>$ingestRoot,'ingestFolders'=>$ingestFolders,'ingestStableSeconds'=>$ingest->stableSeconds(),'ingestError'=>$ingestError,
    ]);
});
$router->post(function(){
    $session=LoginService::getSession(); $owner=(int)$session->getOwner(); $repo=new AiAgentMediaRepository();
    $redirectProject=max(0,(int)($_POST['id']??0));
    try{
        $action=(string)($_POST['action']??'upload');
        if($action==='create_server_folder'){
            $name=(new AiVideoIngestService())->createProjectFolder($owner,(string)($_POST['folder_name']??''));
            MessageUtil::setMessage($name.' was created with source and supporting-media folders. Upload the master by SFTP or use the browser for a file below 250 MB.');
        }elseif($action==='import_server_folder'){
            $ingest=new AiVideoIngestService();$files=$ingest->importable($owner,trim((string)($_POST['folder_name']??'')));$imported=0;$skipped=0;
            foreach($files as $file){
                if($repo->sourceExists($owner,(string)$file['source_url'])){$skipped++;continue;}
                $repo->add($owner,(int)$session->getId(),$file);$imported++;
            }
            if(!$imported)throw new RuntimeException($skipped.' file(s) were already imported from this folder.');
            MessageUtil::setMessage($imported.' server video(s) imported as private projects'.($skipped?' ('.$skipped.' already existed).':'.'));
        }elseif($action==='upload_project_asset'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Project not found.');
            if(!FileUtils::hasFile($_FILES,'project_asset'))throw new RuntimeException('Select a project asset.');
            $relative=(new AiVideoIngestService())->uploadProjectAsset($owner,(string)$job->source_url,$_FILES['project_asset'],(string)($_POST['project_asset_role']??'image'));
            MessageUtil::setMessage($relative.' was attached to this project and is ready for insertion chips.');
        }elseif($action==='upload_asset'){
            if(!FileUtils::hasFile($_FILES,'asset'))throw new RuntimeException('Select a production asset.');
            $file=$_FILES['asset'];$media=(new AiReusableMediaService())->validate($file,(string)($_POST['asset_type']??'IMAGE'));
            $file['type']=$media['mime'];$url=FileUtils::saveFile($file,'agents/video-studio/assets');
            $repo->addAsset($owner,(int)$session->getId(),$media['type'],trim((string)($_POST['asset_name']??''))?:pathinfo((string)$file['name'],PATHINFO_FILENAME),$url,$media['mime']);
            MessageUtil::setMessage('Reusable '.$media['type'].' was added to the media bank.');
        }elseif($action==='duplicate_project'){
            $newId=$repo->duplicate($owner,(int)$session->getId(),(int)($_POST['id']??0));if(!$newId)throw new RuntimeException('Project not found.');$copyTitle=trim((string)($_POST['duplicate_title']??''));if($copyTitle!=='')$repo->rename($owner,$newId,$copyTitle);MessageUtil::setMessage('Project duplicated successfully.');$redirectProject=$newId;
        }elseif($action==='rename_project'){
            $id=(int)($_POST['id']??0);if(!$repo->rename($owner,$id,(string)($_POST['project_title']??'')))throw new RuntimeException('Enter a different project name.');MessageUtil::setMessage('Project name updated.');$redirectProject=$id;
        }elseif($action==='clean_captions'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Project not found.');
            $phrases=preg_split('/[\r\n,;]+/',(string)($_POST['remove_phrases']??''));$selected=trim((string)($_POST['selected_text']??''));if($selected!=='')$phrases[]=$selected;
            $repo->revision($owner,(int)$session->getId(),$job,'CAPTION_CLEANUP','Before removing phrases');
            $result=(new AiCaptionEditorService())->remove((string)$job->transcript_text,(string)$job->subtitles_srt,$phrases,json_decode((string)$job->edit_plan_json,true)?:[]);
            $repo->updateEditor($owner,$id,$result['transcript'],$result['srt'],$result['plan']);MessageUtil::setMessage(count($result['removed_segments']).' caption/video segment(s) marked for removal.');
        }elseif($action==='save_timeline_script'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Project not found.');
            $edits=json_decode((string)($_POST['timeline_edits_json']??''),true);if(!is_array($edits))throw new RuntimeException('The transcript edit could not be read.');
            $repo->revision($owner,(int)$session->getId(),$job,'TIMELINE_TEXT_EDIT','Before transcript-based video cuts');
            $pauseEdits=json_decode((string)($_POST['pause_edits_json']??'[]'),true);
            $precisionEdits=json_decode((string)($_POST['precision_edits_json']??'[]'),true);
            $result=(new AiTranscriptTimelineService())->apply($job,$edits,!empty($_POST['remove_long_pauses']),max(.6,min(10,(float)($_POST['pause_threshold']??1.25))),is_array($pauseEdits)?$pauseEdits:[],is_array($precisionEdits)?$precisionEdits:[]);
            $repo->updateEditor($owner,$id,$result['transcript'],$result['srt'],$result['plan']);
            MessageUtil::setMessage(count($result['removed_segments']).' transcript edit(s) synchronized with the video'.($result['commands']?' and '.count($result['commands']).' inline command(s) added.':'.'));
        }elseif($action==='generate_editing_proxy'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Media job not found.');
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            (new AiVideoProxyService())->generate($owner,$job);MessageUtil::setMessage('The lightweight editing proxy is ready. Final exports will continue using the original master.');
        }elseif($action==='save_speaker_profiles'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Project not found.');
            $plan=json_decode((string)$job->edit_plan_json,true);if(!is_array($plan))$plan=[];$request=(array)($plan['_request']??[]);$profiles=[];
            foreach(array_slice((array)($_POST['speaker']??[]),0,8) as $speaker){
                if(!is_array($speaker))continue;$name=trim((string)($speaker['name']??''));if($name==='')continue;
                $position=(string)($speaker['position']??'center');if(!in_array($position,['left','center','right'],true))$position='center';
                $profiles[]=['name'=>mb_substr($name,0,80),'position'=>$position,'focus_x'=>['left'=>.24,'center'=>.5,'right'=>.76][$position],
                    'caption_preset'=>AiCaptionStyleRegistry::find((string)($speaker['caption_preset']??'classic-bold'))['id'],
                    'caption_color'=>preg_match('/^#[0-9a-f]{6}$/i',(string)($speaker['caption_color']??''))?(string)$speaker['caption_color']:'#ffffff',
                    'voice_reference_start'=>max(0,(float)($speaker['voice_reference_start']??0)),'voice_reference_end'=>max(0,(float)($speaker['voice_reference_end']??0)),
                    'voice_consent'=>!empty($speaker['voice_consent'])];
            }
            $referenceScript=trim((string)($_POST['reference_script_text']??''));
            if(FileUtils::hasFile($_FILES,'reference_script_file')){$file=$_FILES['reference_script_file'];if((int)$file['size']>1024*1024)throw new RuntimeException('The reference script must be below 1 MB.');$extension=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));if(!in_array($extension,['txt','md','srt','vtt'],true))throw new RuntimeException('Reference scripts must be TXT, MD, SRT or VTT.');$referenceScript=trim((string)file_get_contents((string)$file['tmp_name']));}
            $repo->revision($owner,(int)$session->getId(),$job,'SPEAKER_PROFILES','Before participant setup change');$request['speaker_profiles']=$profiles;$request['reference_script']=mb_substr($referenceScript,0,250000);$plan['_request']=$request;
            $repo->updateEditor($owner,$id,(string)$job->transcript_text,(string)$job->subtitles_srt,$plan);MessageUtil::setMessage(count($profiles).' participant profile(s) saved for long edits and derived reels.');
        }elseif($action==='create_selected_reel'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Source project not found.');
            $ids=array_values(array_unique(array_map('intval',(array)($_POST['selected_blocks']??[]))));if(!$ids)throw new RuntimeException('Select at least one transcript block.');
            $timeline=(new AiTranscriptTimelineService())->timeline($job);$selected=array_values(array_filter($timeline['blocks'],fn($block)=>in_array((int)$block['id'],$ids,true)));if(!$selected)throw new RuntimeException('The selected transcript could not be mapped.');
            usort($selected,fn($a,$b)=>(float)$a['start']<=>(float)$b['start']);$start=(float)$selected[0]['start'];$end=(float)$selected[array_key_last($selected)]['end'];
            $range=['start'=>$start,'end'=>$end,'reason'=>'Human-selected transcript blocks: '.implode(',',$ids)];
            $plan=(new App\Services\AiVideoReelService())->plan($job,$range,(string)($_POST['caption_style']??'kinetic'),trim((string)($_POST['reel_instructions']??'')));
            $newId=$repo->createDerivedProject($owner,(int)$session->getId(),$job,$job->title.' — selected reel',$plan);
            MessageUtil::setMessage('Editable reel #'.$newId.' created from your transcript selection.');
            LocationUtils::redirectInternal('panel/growth-hub/video-studio?project='.$newId);return;
        }elseif($action==='improve_timing'){
            $id=(int)($_POST['id']??0);$job=$repo->find($owner,$id);if(!$job)throw new RuntimeException('Media job not found.');
            $repo->updateTranscript($owner,$id,(new AiVideoTranscriptionService())->improveTiming((string)$job->source_url,(string)$job->transcript_json,(string)$job->transcript_text));
            $job=$repo->find($owner,$id);$timeline=(new AiTranscriptTimelineService())->timeline($job);$edits=[];
            foreach($timeline['blocks'] as $block){$text=(string)$block['text'];foreach((array)$block['commands'] as $command){$options=(array)($command['options']??[]);$text.=' ['.(string)($command['type']??'text').': '.(string)($command['instruction']??'').($options?' | '.implode(' | ',$options):'').']';}$edits[]=['id'=>$block['id'],'text'=>$text];}
            $result=(new AiTranscriptTimelineService())->apply($job,$edits,true,1.25,[]);
            $repo->updateEditor($owner,$id,$result['transcript'],$result['srt'],$result['plan']);
            MessageUtil::setMessage('Audio analyzed and '.count($result['removed_segments']).' long silence region(s) automatically reduced. Speech timing remains synchronized.');
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
            $repo->setRenderCaptionMode($owner,$id,(string)($_POST['caption_mode']??'with_captions'));
            $repo->queueRender($owner,$id);
            MessageUtil::setMessage('Final render queued. The downloadable MP4 will appear when the worker finishes.');
        }elseif($action==='save_editor' || $action==='generate_plan'){
            $id=(int)($_POST['id']??0); $job=$repo->find($owner,$id);
            if(!$job) throw new RuntimeException('Media job not found.');
            $projectFiles=(new AiVideoIngestService())->inventoryForSource($owner,(string)$job->source_url);
            $allowedProjectUrls=array_column($projectFiles,'url');
            $transcript=trim((string)($_POST['transcript_text']??''));
            $subtitles=trim((string)($_POST['subtitles_srt']??''));
            $repo->revision($owner,(int)$session->getId(),$job,$action==='generate_plan'?'AI_PLAN':'MANUAL_EDIT','Automatic version before editor save');
            $instructions=trim((string)($_POST['instructions']??''));$props=trim((string)($_POST['visual_insert_instructions']??''));if($props!=='')$instructions.="\nTimed visual inserts requested:\n".$props;
            $existingPlan=json_decode((string)$job->edit_plan_json,true);$existingRequest=is_array($existingPlan)?(array)($existingPlan['_request']??[]):[];$speakerProfiles=(array)($existingRequest['speaker_profiles']??[]);
            if($speakerProfiles){$instructions.="\n\nVerified on-screen participants (do not guess or alternate speakers):";foreach($speakerProfiles as $speaker)$instructions.="\n- ".($speaker['name']??'Speaker')." is at ".($speaker['position']??'center')." (focus_x ".($speaker['focus_x']??.5)."). Use caption preset ".($speaker['caption_preset']??'classic-bold')." and color ".($speaker['caption_color']??'#ffffff').".";}
            if(trim((string)($existingRequest['reference_script']??''))!=='')$instructions.="\n\nHuman reference script for names, terminology and correction context (audio timestamps remain authoritative):\n".mb_substr((string)$existingRequest['reference_script'],0,30000);
            if($projectFiles){$instructions.="\n\nAvailable files in this project's private folder (use the exact asset_url when selecting one):";
                foreach(array_slice($projectFiles,0,120) as $file)$instructions.="\n- {$file['relative_path']} | role={$file['role']} | kind={$file['kind']} | asset_url={$file['url']}";
            }
            $aspect=(string)($_POST['aspect_ratio']??'9:16');if(!in_array($aspect,['9:16','16:9','16:9_4k','1:1','4:5'],true))$aspect='9:16';
            $instructions.="\nMotion direction: ".trim((string)($_POST['motion_direction']??'AI decides purposeful moments only')).". Motion intensity: ".trim((string)($_POST['motion_intensity']??'medium')).".";
            $projectAsset=function(string $field)use($allowedProjectUrls):string{$value=trim((string)($_POST[$field]??''));return str_starts_with($value,'vnv-local://')&&!in_array($value,$allowedProjectUrls,true)?'':$value;};
            $plan=$action==='generate_plan' ? (new AiVideoPlanningService())->createPlan($owner,'openai',$transcript,$instructions,$aspect,(string)($_POST['caption_style']??'clean'),$projectAsset('logo_url'),(string)($_POST['style_preset']??'vnv_premium'),[
                'intro_url'=>$projectAsset('intro_url'),'outro_url'=>$projectAsset('outro_url'),
                'overlay_url'=>$projectAsset('overlay_url'),'audio_url'=>$projectAsset('audio_url'),
            ]) : null;
            if($plan){
                $plan['_request']['caption_preset']=AiCaptionStyleRegistry::find((string)($_POST['caption_preset']??'classic-bold'))['id'];$plan['_request']['caption_size_percent']=max(35,min(140,(int)($_POST['caption_size_percent']??75)));
                $plan['_request']['speaker_profiles']=$speakerProfiles;
                $byName=[];foreach($projectFiles as $file)$byName[strtolower((string)$file['relative_path'])]=$file;
                foreach((array)($plan['generated_inserts']??[]) as $index=>$insert){
                    $assetName=strtolower(trim((string)($insert['asset_name']??'')));$assetUrl=trim((string)($insert['asset_url']??''));
                    if(isset($byName[$assetName])){$file=$byName[$assetName];$plan['generated_inserts'][$index]['asset_url']=$file['url'];$plan['generated_inserts'][$index]['mime_type']=$file['mime_type'];$plan['generated_inserts'][$index]['media_type']='uploaded_asset';$plan['generated_inserts'][$index]['status']='READY';}
                    elseif(str_starts_with($assetUrl,'vnv-local://')&&!in_array($assetUrl,$allowedProjectUrls,true)){$plan['generated_inserts'][$index]['asset_url']='';}
                }
                $layout=json_decode((string)($_POST['overlay_layout_json']??''),true);$plan['_request']['overlay_layout']=is_array($layout)?array_slice($layout,0,30):[];
            }
            if($plan&&!empty($_POST['generate_visual_inserts'])){
                $imageProvider=in_array($_POST['insert_provider']??'', ['openai','gemini'],true)?$_POST['insert_provider']:'openai';
                foreach(array_slice((array)($plan['generated_inserts']??[]),0,3,true) as $index=>$insert){if(trim((string)($insert['prompt']??''))===''||trim((string)($insert['asset_url']??''))!=='')continue;
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
    LocationUtils::redirectInternal('panel/growth-hub/video-studio'.($redirectProject?'?project='.$redirectProject:''));
});
$router->run();
