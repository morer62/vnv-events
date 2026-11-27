<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

/** Writes use the same project, opportunity, task and history records shown by the original applications. */
trait ProductionsWorkboardActions
{
    public function act(string $action,array $form): void
    {
        if (str_starts_with($action,'work_crm_') && !$this->direction && !$this->crmEnabled) { throw new DomainException('Corporate CRM access is required.'); }
        $this->db->beginTransaction();
        try {
            switch ($action) {
                case 'work_crm_note': $this->crmNote($form); break;
                case 'work_crm_document': $this->crmDocument($form); break;
                case 'work_crm_proposal': $this->crmProposal($form); break;
                case 'work_training_review': $this->reviewTraining($form); break;
                case 'work_crm_handoff': $this->handoff($form); break;
                case 'work_original': $this->originalEdition($form); break;
                case 'work_template': $this->template($form); break;
                case 'work_task': $this->updateTask($form); break;
                case 'work_close': $this->closeProject($form); break;
                default: throw new DomainException('Choose a supported production action.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private static function field(array $form,string $key,int $max=500): string
    {
        $value = $form[$key] ?? '';
        if (!is_scalar($value) || mb_strlen(trim((string)$value))>$max) {
            throw new DomainException('One of the submitted fields exceeds the permitted length.');
        }
        return trim((string)$value);
    }

    private static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) {
            throw new DomainException('Choose a valid calendar date.');
        }
        return $value;
    }

    private function opportunity(int $id,bool $lock): array
    {
        $row = $this->query('SELECT x.id,x.program,x.pipeline,x.stage,x.title,x.event_name,x.event_date,x.venue,x.assigned_user_id,x.next_follow_up_at,'
            . self::CATEGORY . ' category FROM commercial_opportunities x WHERE ' . $this->scope() . ' AND x.id=?' . ($lock?' FOR UPDATE':''),
            [...$this->scopeParams(),$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new DomainException('This opportunity is unavailable.'); }
        return $row;
    }

    private function approved(array $op): bool
    {
        return match ($op['program']) {
            'CORPORATE' => $op['stage']==='WON',
            'ACTIVATION' => in_array($op['stage'],['APPROVED','BUILD','LIVE'],true),
            'SPONSORSHIP' => in_array($op['stage'],['CONTRACTED','PAID'],true),
            'NONPROFIT' => in_array($op['stage'],['APPROVED','ACTIVE'],true) && (bool)$this->query("SELECT 1 FROM commercial_support_grants WHERE id_owner=? AND site_key=? AND opportunity_id=? AND status='APPROVED' LIMIT 1",[$this->owner,$this->site,$op['id']])->fetchColumn(),
            default => false,
        };
    }

    private function project(int $id): array
    {
        $row = $this->query('SELECT p.*,x.program,x.assigned_user_id' . self::PROJECT_JOIN . ' WHERE ' . $this->scope() . ' AND p.id=? FOR UPDATE',
            [...$this->scopeParams(),$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new DomainException('This production is unavailable.'); }
        if (in_array(strtoupper($row['status']),['CLOSED','COMPLETED','ARCHIVED','CANCELLED','CANCELED'],true)) {
            throw new DomainException('This production has already closed.');
        }
        return $row;
    }

    private function audit(string $entity,int $id,string $action,array $data=[]): void
    {
        $this->query('INSERT INTO commercial_audit_logs (id_owner,site_key,entity_type,entity_id,action,actor_user_id,after_json) VALUES (?,?,?,?,?,?,?)',
            [$this->owner,$this->site,$entity,$id,$action,$this->actor,json_encode($data,JSON_THROW_ON_ERROR)]);
    }

    private function reviewTraining(array $form): void
    {
        if (!$this->direction) { throw new DomainException('Direction reviews staff training completion.'); }
        $reference=self::field($form,'evidence');
        if ($reference==='') { throw new DomainException('Record the reviewed onboarding reports before completion.'); }
        $id=(int)($form['task_id']??0);
        $row=$this->query("SELECT t.id,t.is_done FROM orders_team_tasks t JOIN users u ON u.id=t.id_user AND u.id_owner=t.id_owner
            WHERE t.id=? AND t.id_owner=? AND t.id_order IS NULL AND t.pmc_project_id IS NULL AND t.pmc_task_key='PMC_TEAM_TRAINING' FOR UPDATE",
            [$id,$this->owner])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new DomainException('This training assignment is unavailable.'); }
        if ($row['is_done']) { return; }
        $this->query('UPDATE orders_team_tasks SET is_done=1,pmc_evidence=? WHERE id=? AND id_owner=?',[$reference,$id,$this->owner]);
        $this->audit('task',$id,'TRAINING_REVIEWED');
    }

    private function crmNote(array $form): void
    {
        $op = $this->opportunity((int)($form['opportunity_id']??0),true);
        $category = self::field($form,'category',40);
        $note = self::field($form,'note');
        $date = self::field($form,'follow_up',10);
        if (!isset(self::CATEGORIES[$category])) { throw new DomainException('Choose an existing relationship category.'); }
        if ($note==='' && $category===$op['category'] && $date===substr((string)$op['next_follow_up_at'],0,10)) {
            throw new DomainException('Enter a note or update the relationship or follow-up date.');
        }
        $due = $date !== '' ? self::date($date).' 09:00:00' : null;
        if ($date === substr((string)$op['next_follow_up_at'],0,10)) { $due = $op['next_follow_up_at']; }
        $this->query('UPDATE commercial_opportunities SET relationship_category=?,next_follow_up_at=? WHERE id=? AND id_owner=? AND site_key=?',
            [$category,$due,$op['id'],$this->owner,$this->site]);
        $this->query("INSERT INTO commercial_activities(id_owner,site_key,opportunity_id,activity_type,summary,due_at,actor_user_id) VALUES(?,?,?,'FOLLOW_UP',?,?,?)",
            [$this->owner,$this->site,$op['id'],$note ?: 'Updated relationship or follow-up date.',$due,$this->actor]);
        $this->audit('opportunity',(int)$op['id'],'FOLLOW_UP_RECORDED',['category'=>$category,'follow_up'=>$date]);
    }

    private function crmDocument(array $form): void
    {
        $op=$this->opportunity((int)($form['opportunity_id']??0),true);
        $title=self::field($form,'document_label',191);
        $url=self::field($form,'document_url',500);
        if ($title==='' || !self::documentUrl($url)) { throw new DomainException('Choose a document title and an HTTPS link in your authorized document workspace.'); }
        $existing=$this->query('SELECT id FROM commercial_media_assets WHERE id_owner=? AND site_key=? AND opportunity_id=? AND storage_path=? LIMIT 1',[$this->owner,$this->site,$op['id'],$url])->fetchColumn();
        if ($existing) { return; }
        $this->query("INSERT INTO commercial_media_assets(id_owner,site_key,opportunity_id,asset_type,storage_path,label,created_by) VALUES(?,?,?,'REFERENCE',?,?,?)",
            [$this->owner,$this->site,$op['id'],$url,$title,$this->actor]);
        $this->audit('opportunity',(int)$op['id'],'DOCUMENT_REFERENCED');
    }

    private function crmProposal(array $form): void
    {
        $op=$this->opportunity((int)($form['opportunity_id']??0),true);
        $latest=$this->query('SELECT id,version_number,status,scope_json FROM commercial_proposals WHERE id_owner=? AND site_key=? AND opportunity_id=? ORDER BY version_number DESC,id DESC LIMIT 1 FOR UPDATE',[$this->owner,$this->site,$op['id']])->fetch(PDO::FETCH_ASSOC);
        $status=self::field($form,'proposal_status',15);
        $ref=self::field($form,'proposal_reference',190);
        if ($ref==='') { throw new DomainException('Record a restricted proposal reference or approval reference.'); }
        if ($status==='DRAFT') {
            $version=(int)($latest['version_number']??0)+1;
            if ($latest && $latest['status']==='DRAFT') { throw new DomainException('Finish the existing draft before adding a revision.'); }
            $this->query("INSERT INTO commercial_proposals(id_owner,site_key,opportunity_id,version_number,status,scope_json) VALUES(?,?,?,?,'DRAFT',?)",
                [$this->owner,$this->site,$op['id'],$version,json_encode(['reference'=>$ref],JSON_THROW_ON_ERROR)]);
        } else {
            if (!$latest || (int)($form['proposal_id']??0)!==(int)$latest['id']
                || !in_array($status, ['SENT','ACCEPTED','DECLINED'],true)
                || ($status==='SENT' && $latest['status']!=='DRAFT')
                || ($status!=='SENT' && $latest['status']!=='SENT')) {
                throw new DomainException('Reopen the latest proposal and choose its next valid status.');
            }
            $scope=json_decode((string)$latest['scope_json'],true) ?: [];
            $scope['decision_reference']=$ref;
            $this->query("UPDATE commercial_proposals SET status=?,scope_json=?,sent_at=CASE WHEN ?='SENT' THEN COALESCE(sent_at,NOW()) ELSE sent_at END,
                accepted_at=CASE WHEN ?='ACCEPTED' THEN COALESCE(accepted_at,NOW()) ELSE accepted_at END WHERE id=? AND id_owner=? AND site_key=?",
                [$status,json_encode($scope,JSON_THROW_ON_ERROR),$status,$status,$latest['id'],$this->owner,$this->site]);
            $version=$latest['version_number'];
        }
        $this->query("INSERT INTO commercial_activities(id_owner,site_key,opportunity_id,activity_type,summary,actor_user_id) VALUES(?,?,?,'PROPOSAL_STATUS',?,?)",
            [$this->owner,$this->site,$op['id'],'Proposal version '.$version.' · '.strtolower($status),$this->actor]);
        $this->audit('opportunity',(int)$op['id'],'PROPOSAL_UPDATED',['version'=>$version,'status'=>$status]);
    }

    private function handoff(array $form): void
    {
        $op = $this->opportunity((int)($form['opportunity_id']??0),true);
        if (!$this->approved($op)) { throw new DomainException('The opportunity needs an approved engagement before production handoff.'); }
        $date = self::date(self::field($form,'date',10));
        $order = (int)($form['order_id']??0);
        $ref = self::field($form,'approval_ref',190);
        if ($ref==='') { throw new DomainException('Record the signed agreement or approved scope reference.'); }
        $existing = $this->query('SELECT id,order_id FROM commercial_projects WHERE id_owner=? AND site_key=? AND opportunity_id=? AND DATE(starts_at)=? FOR UPDATE',[$this->owner,$this->site,$op['id'],$date])->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            if (!$this->direction) { throw new DomainException('Direction links production orders.'); }
            $found = $this->query('SELECT id FROM orders WHERE id_owner=? AND id=? FOR UPDATE',[$this->owner,$order])->fetchColumn();
            if (!$found) { throw new DomainException('The linked order is unavailable.'); }
            $linked = $this->query('SELECT id FROM commercial_projects WHERE id_owner=? AND order_id=? FOR UPDATE',[$this->owner,$order])->fetchColumn();
            if ($linked && (int)$linked!==(int)($existing['id']??0)) { throw new DomainException('This order already belongs to a production.'); }
        }
        if ($existing) {
            if ($order && !$existing['order_id']) {
                $this->project((int)$existing['id']);
                $owners=$this->query('SELECT DISTINCT id_user FROM orders_team_tasks WHERE id_owner=? AND pmc_project_id=?',[$this->owner,$existing['id']])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($owners as $staff) { $this->checkOrderAssignee($order,(int)$staff); }
                $this->query('UPDATE commercial_projects SET order_id=? WHERE id=?',[$order,$existing['id']]);
                $this->query('UPDATE orders_team_tasks SET id_order=? WHERE id_owner=? AND pmc_project_id=? AND id_order IS NULL',[$order,$this->owner,$existing['id']]);
                $this->audit('project',(int)$existing['id'],'ORDER_LINKED',['order_id'=>$order]);
            } elseif ($order && (int)$existing['order_id']!==$order) {
                throw new DomainException('This production already has a different order.');
            }
            return;
        }
        $this->query("INSERT INTO commercial_projects(id_owner,site_key,opportunity_id,order_id,name,status,starts_at,scope_json) VALUES(?,?,?,?,?,'PLANNING',?,?)",
            [$this->owner,$this->site,$op['id'],$order ?: null,$op['event_name'] ?: $op['title'],$date,json_encode(['approval_reference'=>$ref],JSON_THROW_ON_ERROR)]);
        $this->audit('project',(int)$this->db->lastInsertId(),'PRODUCTION_HANDOFF',['opportunity_id'=>$op['id'],'date'=>$date]);
    }

    private function staff(int $id): int
    {
        if (!$this->query('SELECT id FROM users WHERE id_owner=? AND id=? AND is_active=1 AND level IN (3,4) FOR UPDATE',[$this->owner,$id])->fetchColumn()) {
            throw new DomainException('Choose an active team member in this organization.');
        }
        return $id;
    }

    private function checkOrderAssignee(int $order,int $staff): void
    {
        if (!$order) { return; }
        $confirmed=$this->query('SELECT 1 FROM orders_staff_invites i JOIN orders o ON o.id=i.id_order
             WHERE o.id_owner=? AND o.id=? AND i.id_user=? AND i.is_confirmed=1 LIMIT 1',[$this->owner,$order,$staff])->fetchColumn();
        if (!$confirmed) { throw new DomainException('Confirm this team member on the linked order before assigning its work.'); }
    }

    private function originalEdition(array $form): void
    {
        if (!$this->direction) { throw new DomainException('Direction approves original productions.'); }
        $kind = self::field($form,'edition',80);
        $brief = self::field($form,'approval_ref',190);
        if ($brief==='') { throw new DomainException('Enter the approved edition brief reference.'); }
        $manager = $this->staff((int)($form['manager_id']??0));
        if ($kind==='break-club') {
            $event = $this->query('SELECT id,title,starts_at,location FROM vnv_break_events WHERE id=? AND owner_id=? FOR UPDATE',
                [(int)($form['event_id']??0),$this->owner])->fetch(PDO::FETCH_ASSOC);
            if (!$event) { throw new DomainException('Choose an existing club event.'); }
            $key = 'break:'.$event['id'];
            $date = $event['starts_at']; $name = $event['title']; $venue = $event['location'];
        } else {
            $date = self::date(self::field($form,'date',10));
            if (!isset(RetroGlamEditions::TITLES[$kind]) && $kind !== 'other') { throw new DomainException('Choose an original experience.'); }
            $name = $kind === 'other' ? self::field($form,'name',160) : 'Retro Glam · '.RetroGlamEditions::TITLES[$kind];
            if ($name==='') { throw new DomainException('Name the approved edition.'); }
            $key = $kind==='other' ? 'original:'.substr(hash('sha256',mb_strtolower($name)),0,32).':'.$date : $kind.':'.$date;
            $venue = self::field($form,'venue',220);
        }
        $exists = $this->query('SELECT id FROM commercial_projects WHERE id_owner=? AND site_key=? AND original_edition_key=? FOR UPDATE',[$this->owner,$this->site,$key])->fetchColumn();
        if ($exists) { return; }
        $this->query("INSERT INTO commercial_opportunities(id_owner,site_key,program,pipeline,stage,title,event_name,event_date,venue,assigned_user_id,source)
             VALUES(?,?,'ORIGINAL','ORIGINAL_PRODUCTION','APPROVED',?,?,?,?,?,'approved_edition')",
            [$this->owner,$this->site,$name,$name,substr($date,0,10),$venue,$manager]);
        $op = $this->db->lastInsertId();
        $this->query("INSERT INTO commercial_projects(id_owner,site_key,opportunity_id,name,starts_at,original_edition_key,scope_json) VALUES(?,?,?,?,?,?,?)",
            [$this->owner,$this->site,$op,$name,$date,$key,json_encode(['approval_reference'=>$brief],JSON_THROW_ON_ERROR)]);
        $this->audit('project',(int)$this->db->lastInsertId(),'ORIGINAL_APPROVED',['edition'=>$key]);
    }

    private function templateRows(string $program): array
    {
        $key = self::TEMPLATES[$program] ?? '';
        $file = dirname(__DIR__,2).'/docs/production-operations/'.$key.'.csv';
        $input = $key && is_file($file) ? fopen($file,'rb') : false;
        if (!$input) { throw new DomainException('The production template is unavailable.'); }
        try {
            $header = fgetcsv($input,0,',','"', '');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
            if ($header[0] !== 'task_id') { throw new DomainException('The production template format is unsupported.'); }
            $rows = [];
            while (($line=fgetcsv($input,0,',','"', ''))!==false) {
                $rows[] = array_combine($header,$line);
            }
            if (!$rows) { throw new DomainException('The template contains no tasks.'); }
            return $rows;
        } finally { fclose($input); }
    }

    private function template(array $form): void
    {
        $p = $this->project((int)($form['project_id']??0));
        if (!$p['starts_at']) { throw new DomainException('An event date is required to schedule the template.'); }
        $plan = $this->templateRows($p['program']);
        $start = new DateTimeImmutable($p['starts_at']);
        $assigned = $this->staff((int)($form['assignee']??0));
        $this->checkOrderAssignee((int)$p['order_id'],$assigned);
        if ($p['order_id'] && !$this->query('SELECT 1 FROM orders WHERE id=? AND id_owner=? FOR UPDATE',[$p['order_id'],$this->owner])->fetchColumn()) {
            throw new DomainException('Verify the order link before scheduling this production.');
        }
        $existing = $this->query('SELECT pmc_task_key FROM orders_team_tasks WHERE pmc_project_id=? AND id_owner=? FOR UPDATE',[$p['id'],$this->owner])->fetchAll(PDO::FETCH_COLUMN);
        $added=0;
        foreach ($plan as $row) {
            if (in_array($row['task_id'],$existing,true)) { continue; }
            $days=(int)$row['days_from_event'];
            $due = $start->modify(sprintf('%+d days',$days))->format('Y-m-d');
            $this->query("INSERT INTO orders_team_tasks(id_order,id_user,id_owner,id_service,task_description,is_done,pmc_project_id,pmc_task_key,pmc_phase,
                pmc_due_date,pmc_priority,pmc_dependencies,pmc_acceptance,pmc_role) VALUES(?,?,?,0,?,0,?,?,?,?,'normal',?,?,?)",
                [$p['order_id'],$assigned,$this->owner,$row['task'],$p['id'],$row['task_id'],$row['phase'],$due,$row['depends_on'],$row['completion_evidence'],$row['responsible_role']]);
            ++$added;
        }
        if ($added) { $this->audit('project',(int)$p['id'],'TEMPLATE_APPLIED',['count'=>$added,'assignee'=>$assigned]); }
    }

    private function updateTask(array $form): void
    {
        $p=$this->project((int)($form['project_id']??0));
        $id=(int)($form['task_id']??0);
        $t=$this->query('SELECT * FROM orders_team_tasks WHERE id=? AND id_owner=? AND ((pmc_project_id=? AND (id_order IS NULL OR id_order=?)) OR
           (pmc_project_id IS NULL AND id_order=? AND EXISTS(SELECT 1 FROM orders o WHERE o.id=orders_team_tasks.id_order AND o.id_owner=orders_team_tasks.id_owner))) FOR UPDATE',
            [$id,$this->owner,$p['id'],$p['order_id'],$p['order_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$t || (!$this->direction && (int)$t['id_user']!==$this->actor)) { throw new DomainException('This task is unavailable.'); }
        if ($t['is_done'] && (!$t['pmc_task_key'] || $t['pmc_evidence'])) { return; }
        $state=self::field($form,'state',15);
        if ($t['is_done'] && $state!=='done') { throw new DomainException('Add evidence to the completed task.'); }
        if (!in_array($state,['open','done'],true)) { throw new DomainException('Choose a task status.'); }
        $priority=self::field($form,'priority',12);
        if (!in_array($priority,['normal','urgent'],true)) { throw new DomainException('Choose a task priority.'); }
        $date=self::field($form,'due_date',10);
        $due=$date!==''?self::date($date):null;
        $evidence=self::field($form,'evidence');
        $block=self::field($form,'blocker');
        $assigned = $this->staff((int)($form['assignee']??$t['id_user']));
        if ($assigned!==(int)$t['id_user']) { $this->checkOrderAssignee((int)$p['order_id'],$assigned); }
        if ($state==='done') {
            if ($block!=='' || ($t['pmc_task_key'] && $evidence==='')) { throw new DomainException('Resolve blockers and record completion evidence first.'); }
            $done=$this->query('SELECT pmc_task_key FROM orders_team_tasks WHERE pmc_project_id=? AND id_owner=? AND is_done=1 FOR UPDATE',[$p['id'],$this->owner])->fetchAll(PDO::FETCH_COLUMN);
            $deps=preg_split('/\s+/',trim((string)$t['pmc_dependencies']),-1,PREG_SPLIT_NO_EMPTY);
            if (array_diff($deps,$done)) { throw new DomainException('Finish the prerequisite tasks first.'); }
        }
        $this->query('UPDATE orders_team_tasks SET id_user=?,pmc_due_date=?,pmc_priority=?,pmc_blocker=?,pmc_evidence=?,is_done=? WHERE id=? AND id_owner=?',
            [$assigned,$due,$priority,$block?:null,$evidence?:null,$state==='done'?1:0,$id,$this->owner]);
        $this->audit('task',$id,'TASK_UPDATED',['project_id'=>$p['id'],'state'=>$state,'assignee'=>$assigned,'due'=>$due]);
    }

    private function closeProject(array $form): void
    {
        $p=$this->project((int)($form['project_id']??0));
        $rows=$this->query('SELECT t.is_done,t.pmc_task_key,t.pmc_phase,t.pmc_evidence FROM orders_team_tasks t
            WHERE t.id_owner=? AND ((t.pmc_project_id=? AND (t.id_order IS NULL OR t.id_order=?)) OR (t.pmc_project_id IS NULL AND t.id_order=? AND EXISTS(SELECT 1 FROM orders o WHERE o.id=t.id_order AND o.id_owner=t.id_owner))) FOR UPDATE',[$this->owner,$p['id'],$p['order_id'],$p['order_id']])->fetchAll(PDO::FETCH_ASSOC);
        $keys = array_column($rows,'pmc_task_key');
        if (array_diff(array_column($this->templateRows($p['program']),'task_id'),$keys)
            || array_filter($rows,static fn($t)=>!$t['is_done'] || ($t['pmc_task_key'] && !$t['pmc_evidence']))) {
            throw new DomainException('Complete the project checklist and record the template evidence, including Close & Recap, before closing.');
        }
        $result=self::field($form,'results',1500);
        if ($result==='') { throw new DomainException('Record the production results before closing.'); }
        $scope=json_decode((string)$p['scope_json'],true) ?: [];
        $scope['pmc_recap']=['results'=>$result,'incidents'=>self::field($form,'incidents',1000),'lessons'=>self::field($form,'lessons',1000)];
        if ($this->direction) { $scope['pmc_recap']['finance']=self::field($form,'finance',500); }
        $this->query("UPDATE commercial_projects SET status='COMPLETED',ends_at=NOW(),scope_json=? WHERE id=? AND id_owner=? AND site_key=?",
            [json_encode($scope,JSON_THROW_ON_ERROR),$p['id'],$this->owner,$this->site]);
        $this->audit('project',(int)$p['id'],'CLOSE_AND_RECAP');
    }
}
