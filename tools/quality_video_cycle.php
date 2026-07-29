<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoTranscriptionService;

$owner=2;
$user=2;
$sourceId=(int)($argv[1]??8);
$repo=new AiAgentMediaRepository();
$source=$repo->find($owner,$sourceId);
if(!$source)throw new RuntimeException('Source project not found.');

$newId=$repo->duplicate($owner,$user,$sourceId);
if(!$newId)throw new RuntimeException('Unable to create the quality revision.');
$job=$repo->find($owner,$newId);
echo "QUALITY_PROJECT={$newId}\n";
echo "Transcribing with real word timestamps...\n";
$transcription=(new AiVideoTranscriptionService())->transcribe((string)$job->source_url);
$repo->updateTranscript($owner,$newId,$transcription);

$job=$repo->find($owner,$newId);
$plan=json_decode((string)$job->edit_plan_json,true);
if(!is_array($plan))$plan=[];
$request=(array)($plan['_request']??[]);
$commands=array_values(array_filter((array)($request['timeline_commands']??[]),static function($command):bool{
    if(!is_array($command))return false;
    if(($command['type']??'')==='zoom')return false;
    if(($command['type']??'')==='transition'&&preg_match('/flash|zoom/i',(string)($command['instruction']??'')))return false;
    return true;
}));
$request['timeline_commands']=$commands;
$request['pause_edits']=[];
$request['timeline_removed_segments']=[];
$request['removed_segments']=[];
$request['pause_threshold_seconds']=1.25;
$request['caption_style']='kinetic';
$request['caption_preset']='tiktok-pop';
$request['caption_size_percent']=85;
$request['render_preset']='veryfast';
$request['render_crf']=27;
$request['audio_bitrate']='192k';
$request['quality_review']='Word-timed transcription; no automatic silence cuts; no guessed speaker reframes.';
$plan['_request']=$request;
$plan['camera_moves']=[];
$plan['transitions']=array_values(array_filter((array)($plan['transitions']??[]),static fn($transition)=>is_array($transition)&&!preg_match('/flash|zoom/i',(string)($transition['type']??''))));
foreach(['caption_style_events','generated_inserts','text_overlays'] as $field){
    $plan[$field]=array_values(array_filter((array)($plan[$field]??[]),static fn($item)=>is_array($item)&&($item['source']??'')!=='timeline_command'));
}
$timeline=new App\Services\AiTranscriptTimelineService();
foreach($commands as $command){
    $method=new ReflectionMethod($timeline,'applyCommandToPlan');
    $method->setAccessible(true);
    $method->invokeArgs($timeline,[&$plan,$command]);
}
$repo->updateEditor($owner,$newId,(string)$job->transcript_text,(string)$job->subtitles_srt,$plan);
$repo->queueRender($owner,$newId);
$final=$repo->find($owner,$newId);
$raw=json_decode((string)$final->transcript_json,true);
echo 'WORDS='.count((array)($raw['words']??[]))."\n";
echo 'SEGMENTS='.count((array)($raw['segments']??[]))."\n";
echo "STATUS={$final->status}\n";
