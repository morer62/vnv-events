<?php

use App\Repositories\AiAgentMediaRepository;
use App\Services\AiVideoRenderService;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__,2).'/vendor/autoload.php';
$dotenv=Dotenv\Dotenv::createImmutable(dirname(__DIR__,2)); $dotenv->safeLoad();

$repo=new AiAgentMediaRepository();
$renderer=new AiVideoRenderService();
$limit=max(1,min(10,(int)($argv[1]??1)));
for($i=0;$i<$limit;$i++){
    $job=$repo->nextQueuedRender(); if(!$job) break;
    if(!$repo->markRendering((int)$job->id)){continue;}
    try{$repo->completeRender((int)$job->id_owner,(int)$job->id,$renderer->render($job,fn(int $percent,string $stage)=>$repo->updateRenderProgress((int)$job->id_owner,(int)$job->id,$percent,$stage))); echo "Completed media job #{$job->id}\n";}
    catch(\Throwable $e){$repo->fail((int)$job->id_owner,(int)$job->id,$e->getMessage()); fwrite(STDERR,"Failed media job #{$job->id}: {$e->getMessage()}\n");}
}
