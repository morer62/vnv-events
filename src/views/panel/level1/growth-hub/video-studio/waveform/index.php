<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoIngestService;
use App\Services\LoginService;
use App\Utils\JsonResponse;
use App\Utils\Router;

$router=new Router();
$router->get(function(): JsonResponse {
    try{
        $session=LoginService::getSession();$owner=(int)$session->getOwner();$id=max(0,(int)($_GET['id']??0));
        $job=(new AiAgentMediaRepository())->find($owner,$id);if(!$job)throw new RuntimeException('Video project not found.');
        $path=(new AiVideoIngestService())->localPath((string)$job->source_url);if(!$path||!is_file($path)||!is_readable($path))throw new RuntimeException('Private media is unavailable.');
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();@set_time_limit(180);
        $cacheDirectory=dirname(__DIR__,7).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'video-cache'.DIRECTORY_SEPARATOR.'waveforms';
        $cacheKey=hash('sha256',$path.'|'.filesize($path).'|'.filemtime($path));$cacheFile=$cacheDirectory.DIRECTORY_SEPARATOR.$cacheKey.'.json';
        if(is_file($cacheFile)){$cached=json_decode((string)file_get_contents($cacheFile),true);if(is_array($cached)&&!empty($cached['peaks']))return new JsonResponse($cached);}
        $binary=trim((string)($_ENV['FFMPEG_PATH']??getenv('FFMPEG_PATH')?:'ffmpeg'));
        if((str_contains($binary,'/')||str_contains($binary,'\\'))&&!is_file($binary))$binary='ffmpeg';
        $command=[$binary,'-v','error','-i',$path,'-vn','-ac','1','-ar','200','-f','s16le','pipe:1'];
        $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Unable to analyze the audio waveform.');
        $raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        if($code!==0||$raw==='')throw new RuntimeException('FFmpeg waveform analysis failed: '.mb_substr(trim($error),-500));
        $samples=array_values(unpack('s*',$raw)?:[]);$sampleRate=200;$maxPeaks=6000;$bucket=max(1,(int)ceil(count($samples)/$maxPeaks));$peaks=[];
        for($offset=0,$count=count($samples);$offset<$count;$offset+=$bucket){$peak=0;for($i=$offset;$i<min($count,$offset+$bucket);$i++)$peak=max($peak,abs((int)$samples[$i]));$peaks[]=round($peak/32768,4);}
        $payload=['success'=>true,'duration'=>count($samples)/$sampleRate,'peaks'=>$peaks];
        if((is_dir($cacheDirectory)||mkdir($cacheDirectory,0770,true)||is_dir($cacheDirectory)))file_put_contents($cacheFile,json_encode($payload,JSON_UNESCAPED_SLASHES),LOCK_EX);
        return new JsonResponse($payload);
    }catch(Throwable $e){return new JsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
});
$router->run();
