<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__.'/../../')->load();

use App\Services\GourmetRecurringBillingService;

$dryRun=in_array('--dry-run',$argv??[],true);
$lock=fopen(sys_get_temp_dir().DIRECTORY_SEPARATOR.'vnv-gourmet-recurring-billing.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDOUT,"Another recurring billing worker is active.\n");exit(0);}
try{
    $result=(new GourmetRecurringBillingService())->processDue(25,$dryRun);
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($result['failed']>0?2:0);
}catch(Throwable $e){fwrite(STDERR,'Recurring billing worker failed: '.$e->getMessage().PHP_EOL);exit(1);}
