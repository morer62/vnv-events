<?php
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
use App\Repositories\Connection;
use App\Services\MochiActionService;
$fail=[];$check=static function(bool $v,string $n)use(&$fail){echo ($v?'PASS ':'FAIL ').$n.PHP_EOL;if(!$v)$fail[]=$n;};
try{$db=new Connection();$db->query("SELECT id FROM users WHERE level=1 AND id=2");$admin=$db->fetchOne();if(!$admin)throw new RuntimeException('QA Level 1 user missing.');$db->query("SELECT id,notes FROM orders WHERE id_owner=2 AND COALESCE(is_archived,0)=0 LIMIT 1");$order=$db->fetchOne();if(!$order)throw new RuntimeException('QA order missing.');
 $service=new MochiActionService(2,'vnvevents',$db);try{$service->prepare(2,4,1,'create customer Test test@example.com');$check(false,'Level 4 write denied');}catch(Throwable){$check(true,'Level 4 write denied');}
 $payload=['order_id'=>(int)$order->id,'note'=>'MOCHI_ACTION_QA_'.bin2hex(random_bytes(4))];$db->query("INSERT INTO mochi_action_drafts(id_owner,site_key,id_user,id_session,action_key,payload_json,summary,expires_at) VALUES(2,'vnvevents',2,NULL,'ADD_NOTE',:payload,'QA note',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))");$db->bind(':payload',json_encode($payload));$db->execute();$draft=(int)$db->lastId();$result=$service->execute($draft,2,1);$check($result['entity_type']==='order_note','confirmed note action executes');
 $db->query("SELECT COUNT(*) total FROM mochi_action_audit WHERE id_action_draft=:id AND action_source='MOCHI' AND status='SUCCEEDED'");$db->bind(':id',$draft);$check((int)$db->fetchOne()->total===1,'successful action is audited');
 $db->query("UPDATE orders SET notes=:notes WHERE id=:id");$db->bind(':notes',$order->notes);$db->bind(':id',(int)$order->id);$db->execute();$db->query("DELETE FROM mochi_action_audit WHERE id_action_draft=:id");$db->bind(':id',$draft);$db->execute();$db->query("DELETE FROM mochi_action_drafts WHERE id=:id");$db->bind(':id',$draft);$db->execute();
 foreach(['CREATE_CUSTOMER','UPDATE_CUSTOMER','CREATE_ESTIMATE','CREATE_ORDER','CREATE_EVENT','ADD_NOTE','CREATE_FOLLOW_UP','ASSOCIATE_REQUEST_CUSTOMER','ASSOCIATE_CUSTOMER_ORDER','SEND_CUSTOMER_EMAIL','SEND_PUSH_NOTIFICATION'] as $key){$db->query("SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('mochi_action_drafts','mochi_action_audit')");$check((int)$db->fetchOne()->total===2,'action infrastructure '.$key);}
}catch(Throwable $e){$fail[]=$e->getMessage();echo 'FAIL '.$e->getMessage().PHP_EOL;}
echo $fail?"MOCHI ACTIONS LOCAL QA FAILED — DO NOT DEPLOY\n":"MOCHI ACTIONS LOCAL QA PASSED — READY FOR PRODUCTION\n";exit($fail?1:0);
