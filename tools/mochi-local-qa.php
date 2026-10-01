<?php
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
use App\Repositories\Connection;
use App\Services\MochiSessionService;
use App\Services\MochiOperationalToolsService;

$fail=[];$ok=static function(bool $condition,string $name)use(&$fail){echo ($condition?'PASS ':'FAIL ').$name.PHP_EOL;if(!$condition)$fail[]=$name;};
try{$db=new Connection();$db->beginTransaction();$db->query("SELECT id,id_owner,level FROM users WHERE level IN (1,4,5) ORDER BY FIELD(level,1,4,5)");$users=$db->fetchAll();$by=[];foreach($users as $u)$by[(int)$u->level]=$u;$admin=$by[1]??null;if(!$admin)throw new RuntimeException('No Level 1 QA user exists.');$owner=(int)$admin->id;
 $sessions=new MochiSessionService($owner,'vnvevents',$db);$a=$sessions->today((int)$admin->id,'2026-09-30');$b=$sessions->today((int)$admin->id,'2026-10-01');$ok((int)$a->id!==(int)$b->id,'daily reset creates a new session');$ok(count($sessions->history((int)$admin->id))>=2,'daily history is retained');
 $tools=new MochiOperationalToolsService($owner,'vnvevents',$db);$ok(count($tools->suggestions(1))===4,'Level 1 has four suggestions');$ok(count($tools->suggestions(4))===4,'Level 4 has four suggestions');$ok(count($tools->suggestions(5))===4,'Level 5 has four suggestions');
 foreach(['weekend_events','collections','opportunities','requests','contracts','catalog','warehouse','website'] as $tool){$result=$tools->run($tool,(int)$admin->id,1,(int)$b->id,$tool==='catalog'?'Seafood Paella':'');$ok(isset($result['reply'],$result['items']),'Level 1 tool '.$tool);}
 if(isset($by[4])){try{$tools->run('collections',(int)$by[4]->id,4,(int)$b->id);$ok(false,'Level 4 financial denial');}catch(Throwable){$ok(true,'Level 4 financial denial');}}
 if(isset($by[5])){try{$tools->run('weekend_events',(int)$by[5]->id,5,(int)$b->id);$ok(false,'Level 5 all-event denial');}catch(Throwable){$ok(true,'Level 5 all-event denial');}}
 $db->query("SELECT id,name,price FROM store_products WHERE id_owner=:owner AND site_key='vnvevents' AND status='ACTIVE' AND is_public=1 AND product_type='FIXED' AND COALESCE(promo_price,0)=0 LIMIT 1");$db->bind(':owner',$owner);$p=$db->fetchOne();if($p){$before=$tools->run('catalog',(int)$admin->id,1,(int)$b->id,(string)$p->name);$db->query('UPDATE store_products SET price=price+1 WHERE id=:id');$db->bind(':id',(int)$p->id);$db->execute();$after=$tools->run('catalog',(int)$admin->id,1,(int)$b->id,(string)$p->name);$ok(json_encode($before)!==json_encode($after),'catalog reflects transactional price change');}
 $db->rollback();
}catch(Throwable $e){$fail[]=$e->getMessage();echo 'FAIL '.$e->getMessage().PHP_EOL;try{if(isset($db))$db->rollback();}catch(Throwable){}}
echo $fail?"MOCHI LOCAL QA FAILED — DO NOT DEPLOY\n":"MOCHI LOCAL QA PASSED — READY FOR PRODUCTION\n";exit($fail?1:0);
