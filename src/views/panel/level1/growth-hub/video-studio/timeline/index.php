<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiTranscriptTimelineService;
use App\Services\LoginService;
use App\Utils\JsonResponse;
use App\Utils\Router;

$router=new Router();
$router->post(function(): JsonResponse {
    try{
        $session=LoginService::getSession();$owner=(int)$session->getOwner();$id=max(0,(int)($_POST['id']??0));$repo=new AiAgentMediaRepository();$job=$repo->find($owner,$id);
        if(!$job)throw new RuntimeException('Video project not found.');
        $edits=json_decode((string)($_POST['timeline_edits_json']??''),true);if(!is_array($edits))throw new RuntimeException('The transcript edit could not be read.');
        $pauseEdits=json_decode((string)($_POST['pause_edits_json']??'[]'),true);
        $precisionEdits=json_decode((string)($_POST['precision_edits_json']??'[]'),true);
        $result=(new AiTranscriptTimelineService())->apply($job,$edits,!empty($_POST['remove_long_pauses']),max(.6,min(10,(float)($_POST['pause_threshold']??1.25))),is_array($pauseEdits)?$pauseEdits:[],is_array($precisionEdits)?$precisionEdits:[]);
        $repo->updateEditor($owner,$id,$result['transcript'],$result['srt'],$result['plan']);
        return new JsonResponse(['success'=>true,'removed_segments'=>$result['removed_segments'],'removed_segment_count'=>count($result['removed_segments']),'commands'=>count($result['commands']),'saved_at'=>date('H:i:s')]);
    }catch(Throwable $e){return new JsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
});
$router->run();
