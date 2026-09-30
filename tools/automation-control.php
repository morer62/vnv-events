<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$command=(string)($argv[1]??'status');
if(!in_array($command,['status','enable-live','pause','retry-failed'],true)){
    fwrite(STDERR,"Usage: php tools/automation-control.php [status|enable-live|pause|retry-failed]\n");exit(2);
}

try{
    $pdo=new PDO((string)$_ENV['DATABASE_URL']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    if($command==='enable-live')$pdo->exec("UPDATE automation_settings SET master_enabled=1,dry_run=0 WHERE id_owner=2 AND site_key='vnvevents'");
    elseif($command==='pause')$pdo->exec("UPDATE automation_settings SET master_enabled=0 WHERE id_owner=2 AND site_key='vnvevents'");
    elseif($command==='retry-failed')$pdo->exec("UPDATE automation_outbox SET available_at=UTC_TIMESTAMP() WHERE id_owner=2 AND site_key='vnvevents' AND status='FAILED' AND attempts<4");
    $settings=$pdo->query("SELECT master_enabled,dry_run,timezone,quiet_hours_start,quiet_hours_end FROM automation_settings WHERE id_owner=2 AND site_key='vnvevents'")->fetch(PDO::FETCH_ASSOC);
    $reviewers=(int)$pdo->query("SELECT COUNT(*) FROM automation_reviewers WHERE id_owner=2 AND site_key='vnvevents' AND status='ACTIVE'")->fetchColumn();
    $pending=(int)$pdo->query("SELECT COUNT(DISTINCT dedupe_key) FROM automation_outbox WHERE id_owner=2 AND site_key='vnvevents' AND status='AWAITING_APPROVAL'")->fetchColumn();
    $counts=$pdo->query("SELECT status,COUNT(*) total FROM automation_outbox WHERE id_owner=2 AND site_key='vnvevents' GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $errors=$pdo->query("SELECT id,channel,message_type,attempts,last_error FROM automation_outbox WHERE id_owner=2 AND site_key='vnvevents' AND status='FAILED' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    fwrite(STDOUT,json_encode(['settings'=>$settings,'active_reviewers'=>$reviewers,'recommendations_awaiting_approval'=>$pending,'outbox_counts'=>$counts,'recent_errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}catch(Throwable $e){fwrite(STDERR,"Automation control failed: ".$e->getMessage()."\n");exit(1);}
