<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;

$db=new Connection();
$db->query("SELECT mm.id_owner,mm.site_key,mm.scope_type,mm.scope_key,mm.memory_json,DATE(mm.updated_at) memory_date,CASE WHEN mm.scope_type='SESSION' THEN CAST(mm.scope_key AS UNSIGNED) ELSE NULL END id_session FROM mochi_memories mm ORDER BY mm.id");
$memories=$db->fetchAll();$inserted=0;$categories=['topics'=>'TOPIC','verified_facts'=>'VERIFIED_FACT','preferences_rules'=>'PREFERENCE','corrections'=>'CORRECTION','open_threads'=>'OPEN_THREAD'];
foreach($memories as $row){$memory=json_decode((string)$row->memory_json,true);if(!is_array($memory))continue;foreach($categories as $key=>$category)foreach((array)($memory[$key]??[]) as $entry){$content=trim((string)$entry);if($content==='')continue;$hash=hash('sha256',$category.'|'.mb_strtolower($content));$db->query('INSERT INTO mochi_learning_journal(id_owner,site_key,id_session,memory_date,category,content,content_hash,source_tool,is_verified) VALUES(:owner,:site,:session,:memory_date,:category,:content,:content_hash,:source_tool,:verified) ON DUPLICATE KEY UPDATE last_seen_at=NOW(),occurrence_count=occurrence_count+1');foreach(['owner'=>(int)$row->id_owner,'site'=>(string)$row->site_key,'session'=>$row->id_session? (int)$row->id_session:null,'memory_date'=>(string)$row->memory_date,'category'=>$category,'content'=>mb_substr($content,0,1200),'content_hash'=>$hash,'source_tool'=>'memory_backfill','verified'=>$category==='VERIFIED_FACT'?1:0] as $name=>$value)$db->bind(':'.$name,$value);$db->execute();$inserted++;}}
echo 'MOCHI learning journal backfill processed '.$inserted." entries.\n";
