<?php

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Services\SocialEditorialAgentService;
use App\Services\SocialEditorialPublishingWorker;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$command=$argv[1]??'run';
try{
    $result=$command==='publish-due'
        ?(new SocialEditorialPublishingWorker())->run((int)($argv[2]??20))
        :(new SocialEditorialAgentService())->runAll($argv[2]??'',0,$command==='scheduled'?'SCHEDULE':'MANUAL');
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'SOCIAL MEDIA AGENT FAILED: '.$e->getMessage().PHP_EOL);exit(1);}
