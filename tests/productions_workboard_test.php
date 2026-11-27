<?php

declare(strict_types=1);

use App\Services\ProductionsWorkboard;
use App\Services\ProductionsWorkboardSchema;

$root = dirname(__DIR__);
foreach (['RetroGlamEditions','ProductionsWorkboardSchema','ProductionsWorkboardActions','ProductionsWorkboard'] as $name) { require_once "$root/src/Services/$name.php"; }

$port = (int)(getenv('VNV_PMC_TEST_PORT') ?: 33742);
$db = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", getenv('VNV_PMC_TEST_USER') ?: 'root', getenv('VNV_PMC_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema = 'pmc_workboard_test_'.bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
function check(bool $value,string $why): void { if (!$value) { throw new RuntimeException($why); } }
function denied(callable $fn): void { try { $fn(); } catch (DomainException $e) { return; } throw new RuntimeException('Expected an unavailable action.'); }
try {
    $db->exec("USE `$schema`");
    $migration=file_get_contents($root.'/db/migrations/20261112150000_create_commercial_partnership_crm.php');
    if (!preg_match("/<<<'SQL'\R(.*?)\RSQL\);/s",$migration,$parts)) { throw new RuntimeException('Cannot load commercial foundation schema.'); }
    $db->exec($parts[1]);
    $db->exec(file_get_contents($root.'/db/20261125_vnv_break_club.sql'));
    $db->exec("CREATE TABLE users(id INT PRIMARY KEY,id_owner INT,level INT,is_active INT,name VARCHAR(100),lastname VARCHAR(100));
        CREATE TABLE orders(id INT UNSIGNED PRIMARY KEY,id_owner INT);
        CREATE TABLE orders_staff_invites(id_order INT,id_user INT,is_confirmed INT);
        INSERT INTO orders_staff_invites VALUES(101,44,1),(101,45,0);
        CREATE TABLE orders_team_tasks(id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,id_order INT UNSIGNED NOT NULL,id_owner INT,id_user INT,id_service INT,task_description TEXT,is_done TINYINT DEFAULT 0,FOREIGN KEY(id_order) REFERENCES orders(id));
        INSERT INTO users VALUES (2,2,1,1,'Direction',''),(44,2,4,1,'Assigned','Producer'),(45,2,4,1,'Other','Producer'),(46,3,4,1,'Other','Tenant'),(48,2,5,1,'Customer','');
        INSERT INTO orders VALUES(101,2),(102,3);
        INSERT INTO orders_team_tasks(id_order,id_owner,id_user,id_service,task_description,is_done) VALUES(101,2,44,0,'Legacy team task',0);");
    ProductionsWorkboardSchema::apply($db);
    ProductionsWorkboardSchema::apply($db);
    $db->exec("INSERT INTO commercial_opportunities(id,id_owner,site_key,program,pipeline,stage,title,event_name,event_date,assigned_user_id)
        VALUES(201,2,'vnvevents','CORPORATE','CORPORATE','WON','Conference','Summit','2026-01-01',44),
        (202,2,'vnvevents','NONPROFIT','NONPROFIT_APPLICATION','APPROVED','Other foundation',NULL,NULL,45),
        (203,3,'vnvevents','CORPORATE','CORPORATE','WON','External tenant',NULL,NULL,44),
        (204,2,'another','CORPORATE','CORPORATE','WON','Wrong site',NULL,NULL,44),
        (205,2,'vnvevents','ORIGINAL','ORIGINAL_WAITLIST','WAITLIST','Retro expression','Retro Glam · 1970s',NULL,44);
        INSERT INTO commercial_proposals(id,id_owner,site_key,opportunity_id,version_number,status) VALUES(501,2,'vnvevents',201,1,'SENT'),(502,2,'vnvevents',201,2,'ACCEPTED'),(503,2,'vnvevents',202,1,'SENT');
        INSERT INTO vnv_break_members(id,owner_id,name,email,phone,party_size,category,status,consent_at) VALUES(1,2,'AA','a@example.org','test',2,'parents','ACTIVE',NOW()),(2,3,'BB','b@example.org','test',1,'parents','ACTIVE',NOW());
        INSERT INTO vnv_break_events(id,owner_id,title,location,starts_at,price_cents,status) VALUES(1,2,'Winter Break','Room A','2026-12-15 18:00:00',2000,'PUBLISHED'),(2,3,'External Break','Room B','2026-12-16 18:00:00',2000,'PUBLISHED');
        INSERT INTO vnv_break_registrations(event_id,member_id,party_size,name,email,phone,unit_cents,status,invited_at,paid_cents,companion_checked_at) VALUES(1,1,2,'AA','a@example.org','test',2000,'PAID',NOW(),4000,NOW()),(1,2,1,'Cross-tenant registration','b@example.org','test',2000,'PAID',NOW(),2000,NULL);
        INSERT INTO orders_team_tasks(id_owner,id_user,task_description,pmc_task_key) VALUES(2,44,'Review training','PMC_TEAM_TRAINING');");
    $dir=new ProductionsWorkboard($db,2,'vnvevents',2,true);
    $team=new ProductionsWorkboard($db,2,'vnvevents',44,false,true);
    $other=new ProductionsWorkboard($db,2,'vnvevents',45,false,true);
    $base=new ProductionsWorkboard($db,2,'vnvevents',44,false);
    $crm=$team->view('crm');
    check(count($crm['crm_rows'])===2 && $crm['proposal_count']===0,'CRM owner / site / assignee or superseded proposal count.');
    denied(fn()=>$base->view('crm'));
    denied(fn()=>$base->act('work_crm_note',['opportunity_id'=>201]));
    denied(fn()=>$team->view('crm',['opportunity'=>202]));
    $team->act('work_crm_note',['opportunity_id'=>201,'category'=>'government','note'=>'Review procurement response.','follow_up'=>'2026-12-01']);
    check($team->view('crm',['category'=>'government'])['crm_total']===1,'Relationship and follow-up filter.');
    $team->act('work_crm_document',['opportunity_id'=>201,'document_label'=>'Event brief','document_url'=>'https://docs.google.com/document/d/test/edit']);
    denied(fn()=>$team->act('work_crm_document',['opportunity_id'=>201,'document_label'=>'Unrelated website','document_url'=>'https://outside.example/']));
    $team->act('work_crm_proposal',['opportunity_id'=>201,'proposal_status'=>'DRAFT','proposal_reference'=>'Revised event scope']);
    $detail=$team->view('crm',['opportunity'=>201]);
    check($detail['documents'][0]['url']==='https://docs.google.com/document/d/test/edit' && $detail['proposals'][0]['version_number']==3,'Restricted document and new proposal version.');
    $nextProposal=$detail['proposals'][0]['id'];
    $team->act('work_crm_proposal',['opportunity_id'=>201,'proposal_id'=>$nextProposal,'proposal_status'=>'SENT','proposal_reference'=>'Sent by commercial owner.']);
    $team->act('work_crm_proposal',['opportunity_id'=>201,'proposal_id'=>$nextProposal,'proposal_status'=>'ACCEPTED','proposal_reference'=>'Signed revision 3.']);
    check($team->view('crm')['proposal_count']===0,'Accepted latest proposal must leave pending.');
    $handoff=['opportunity_id'=>201,'date'=>'2026-01-01','approval_ref'=>'Signed scope 12'];
    denied(fn()=>$team->act('work_crm_handoff',$handoff+['order_id'=>101]));
    denied(fn()=>$dir->act('work_crm_handoff',$handoff+['order_id'=>102]));
    denied(fn()=>$other->act('work_crm_handoff',['opportunity_id'=>202,'date'=>'2026-01-01','approval_ref'=>'Unsigned']));
    $team->act('work_crm_handoff',$handoff);
    $dir->act('work_crm_handoff',$handoff+['order_id'=>101]); $dir->act('work_crm_handoff',$handoff+['order_id'=>101]);
    $active=$team->view('active');
    check(count($active['projects'])===1 && !$active['projects'][0]['closed'] && $active['projects'][0]['total']===1,'Legacy task and overdue event remain active.');
    $pid=(int)$active['projects'][0]['id'];
    denied(fn()=>$other->act('work_template',['project_id'=>$pid,'assignee'=>45]));
    denied(fn()=>$team->act('work_template',['project_id'=>$pid,'assignee'=>46]));
    denied(fn()=>$team->act('work_template',['project_id'=>$pid,'assignee'=>48]));
    denied(fn()=>$team->act('work_template',['project_id'=>$pid,'assignee'=>45]));
    for($i=0;$i<2;$i++) { $team->act('work_template',['project_id'=>$pid,'assignee'=>44]); }
    $selected=$team->view('active',['project'=>$pid])['selected'];
    check($selected['total']===12 && $selected['blocked']>0 && $selected['phase']==='Discover','Template emitted once / dependency rank.');
    $byKey=array_column($selected['tasks'],'id','pmc_task_key');
    $finish=['project_id'=>$pid,'assignee'=>44,'priority'=>'normal','state'=>'done','evidence'=>'Accepted by project lead.'];
    denied(fn()=>$team->act('work_task',$finish+['task_id'=>$byKey['C02']]));
    denied(fn()=>$team->act('work_close',['project_id'=>$pid,'results'=>'Too soon.']));
    foreach ($byKey as $key=>$id) { if ($key==='') { $team->act('work_task',$finish+['task_id'=>$id]); } }
    for($i=1;$i<=11;$i++) { $team->act('work_task',$finish+['task_id'=>$byKey[sprintf('C%02d',$i)]]); }
    $dir->act('work_close',['project_id'=>$pid,'results'=>'Conference delivered.','lessons'=>'Schedule rehearsal earlier.','finance'=>'Finance reconciled.']);
    $past=$team->view('past',['project'=>$pid]);
    check($past['selected']['progress']===100 && !isset($past['selected']['recap']['finance']) && count($other->view('past')['projects'])===0,'Closeout / finance and assignment boundary.');
    $org=$team->view('originals');
    check($org['club_members']===1 && $org['tickets']===2 && (int)$org['club_events'][0]['attendance']===1 && !isset($org['club_events'][0]['net_cents']),'Guests, paid tickets and net receipts isolated.');
    $original=['edition'=>'break-club','event_id'=>1,'manager_id'=>44,'approval_ref'=>'Signed edition brief'];
    denied(fn()=>$team->act('work_original',$original));
    $dir->act('work_original',$original);$dir->act('work_original',$original);
    $orig=$team->view('originals');
    check(count($orig['projects'])===1 && count($orig['editions'])===1,'Original production kept separate from waitlist.');
    check(count($team->view('originals',['edition'=>1])['guest_register'])===1 && !$other->view('originals',['edition'=>1])['guest_register'],'Named guest register is limited to assigned event managers.');
    $team->act('work_template',['project_id'=>$orig['projects'][0]['id'],'assignee'=>44]);
    check($team->view('originals',['project'=>$orig['projects'][0]['id']])['selected']['total']===11,'Standalone original tasks need no catering order.');
    $training=(int)$db->query("SELECT id FROM orders_team_tasks WHERE pmc_task_key='PMC_TEAM_TRAINING'")->fetchColumn();
    check(count(array_filter($team->view('tasks')['tasks'],fn($t)=>$t['id']==$training))===1,'Staff training discoverable.');
    check(!array_filter($other->view('tasks')['tasks'],fn($t)=>$t['id']==$training),'Staff training isolation.');
    denied(fn()=>$team->act('work_training_review',['task_id'=>$training,'evidence'=>'finished']));
    $dir->act('work_training_review',['task_id'=>$training,'evidence'=>'Reviewed checklists L2/L4/L5']);
    check((int)$db->query("SELECT COUNT(*) FROM commercial_audit_logs")->fetchColumn()>=18,'Writes audited.');
    echo "PASS: existing records, CRM categories and permissions, proposal revision, project handoff and dates, templates, task dependencies, closeout, isolated originals and staff training.\n";
} finally {
    $db->exec("DROP DATABASE `$schema`");
}
