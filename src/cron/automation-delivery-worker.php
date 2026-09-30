<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
use App\Services\AutomationCenterService;
$lock=fopen(sys_get_temp_dir().DIRECTORY_SEPARATOR.'vnv-automation-delivery.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDOUT,"Automation delivery worker already active.\n");exit(0);}
try{$result=(new AutomationCenterService())->processOutbox(30);fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL);exit(($result['failed']??0)>0?2:0);}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
