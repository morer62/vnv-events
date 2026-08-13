<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiProviderImageService;
use App\Services\AiVideoIngestService;
use App\Services\LoginService;

header('Content-Type: application/json; charset=utf-8');

$session=LoginService::getSession();
if(!$session||(int)$session->getLevel()!==1){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Forbidden.']);exit;}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}

try{
    $owner=(int)$session->getOwner();$id=(int)($_POST['id']??0);$prompt=trim((string)($_POST['prompt']??''));$ratio=(string)($_POST['aspect_ratio']??'16:9');
    if($prompt===''||mb_strlen($prompt)<8)throw new RuntimeException('Describe the asset you want to generate.');
    if(!in_array($ratio,['16:9','9:16','1:1'],true))$ratio='16:9';
    $job=(new AiAgentMediaRepository())->find($owner,$id);if(!$job)throw new RuntimeException('Video project not found.');
    $generated=(new AiProviderImageService())->generate($owner,'openai',$prompt,$ratio);
    $asset=(new AiVideoIngestService())->saveRemoteProjectAsset($owner,(string)$job->source_url,(string)$generated['url'],$prompt);
    echo json_encode(['ok'=>true,'asset'=>$asset],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
