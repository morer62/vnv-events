<?php
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;
use App\Services\MochiMemoryService;

$db=new Connection();
try{
    $db->beginTransaction();
    $db->query('SELECT id FROM users WHERE id=2 OR (level=1 AND is_active=1) ORDER BY id=2 DESC LIMIT 1');
    $user=(int)$db->fetchOne()->id;
    $db->query("INSERT INTO mochi_sessions(id_owner,site_key,id_user,session_date,sequence_no,title,status) VALUES(2,'vnvevents',:user,'2099-12-31',1,'QA memory','ARCHIVED')");
    $db->bind(':user',$user);$db->execute();$session=(int)$db->lastId();
    $db->query("INSERT INTO automation_conversations(id_owner,site_key,thread_key,id_mochi_session,id_author,author_type,message) VALUES(2,'vnvevents','qa:memory',:session,:author,'HUMAN',:message)");
    $db->bind(':session',$session);$db->bind(':author',$user);$db->bind(':message','Recuerda que el contrato general es la fuente principal y los catálogos son las fuentes de servicios.');$db->execute();
    $memory=new MochiMemoryService(2,'vnvevents',$db);$memory->capture($session,'Entendido; conservaré esa jerarquía.',['tool'=>'conversation','facts'=>[]]);$context=$memory->context($session);
    if(empty($context['today']['summary'])||empty($context['today']['preferences_rules']))throw new RuntimeException('Memory was not persisted and reloaded.');
    echo "MOCHI persistent memory QA: PASS\n";
    $db->rollback();
}catch(Throwable $e){try{$db->rollback();}catch(Throwable){}fwrite(STDERR,'MOCHI persistent memory QA: FAIL - '.$e->getMessage().PHP_EOL);exit(1);}
