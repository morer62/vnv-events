<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

final class ProductionsWorkboard
{
    use ProductionsWorkboardActions;

    public const PHASES = ['Discover', 'Design the Scope', 'Build the System', 'Produce the Event', 'Close & Recap'];
    public const CATEGORIES = [
        'corporate' => 'Corporate clients', 'sponsor' => 'Sponsors', 'donor' => 'Donors',
        'nonprofit' => 'Nonprofit organizations', 'government' => 'Government opportunities',
        'partner' => 'Strategic partners', 'vendor' => 'Vendors', 'original' => 'Original experiences',
        'activation' => 'Brand activations',
    ];
    private const TEMPLATES = ['CORPORATE'=>'corporate','NONPROFIT'=>'nonprofit','ACTIVATION'=>'brand-activation','SPONSORSHIP'=>'brand-activation','ORIGINAL'=>'original-experiences'];
    private const CATEGORY = "COALESCE(NULLIF(x.relationship_category,''), CASE x.program WHEN 'CORPORATE' THEN 'corporate' WHEN 'SPONSORSHIP' THEN 'sponsor' WHEN 'NONPROFIT' THEN 'nonprofit' WHEN 'ORIGINAL' THEN 'original' WHEN 'ACTIVATION' THEN 'activation' END)";
    private const PROJECT_JOIN = " FROM commercial_projects p JOIN commercial_opportunities x ON x.id=p.opportunity_id AND x.id_owner=p.id_owner AND x.site_key=p.site_key ";
    private const ORG_JOIN = " LEFT JOIN commercial_organizations org ON org.id=x.organization_id AND org.id_owner=x.id_owner AND org.site_key=x.site_key ";

    public function __construct(private PDO $db, private int $owner, private string $site, private int $actor, private bool $direction, private bool $crmEnabled = false)
    {
        if ($owner <= 0 || $actor <= 0 || $site !== 'vnvevents') {
            throw new DomainException('Productions is unavailable for this organization.');
        }
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s;
    }

    private function scope(): string
    {
        return 'x.id_owner=? AND x.site_key=?' . ($this->direction ? '' : ' AND x.assigned_user_id=?');
    }

    private function scopeParams(): array
    {
        return $this->direction ? [$this->owner,$this->site] : [$this->owner,$this->site,$this->actor];
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
    }

    public function view(string $tab, array $filters = []): array
    {
        $out = ['direction'=>$this->direction, 'categories'=>self::CATEGORIES, 'filters'=>[],
            'teams'=>[], 'metric'=>[], 'tasks'=>[], 'projects'=>[], 'today'=>$this->today()];
        $keys = ['q','type','manager','from','to','category','scope','page','project','opportunity','edition'];
        foreach ($keys as $key) {
            if (isset($filters[$key]) && is_scalar($filters[$key])) {
                $out['filters'][$key] = mb_substr(trim((string)$filters[$key]), 0, 140);
            }
        }
        $f = $out['filters'];
        if ($tab==='crm' && !$this->direction && !$this->crmEnabled) { throw new DomainException('Corporate CRM access is required.'); }
        if (($this->direction || $this->crmEnabled) && in_array($tab, ['crm','overview','reports'], true)) {
            $out += $this->crm($f, $tab === 'crm');
        }
        if (in_array($tab, ['originals','overview','reports'], true)) {
            $out += $this->originals($tab==='originals' ? (int)($f['edition']??0) : 0);
        }
        if ($tab === 'crm') {
            return $out;
        }

        $projects = $this->query('SELECT p.id,p.opportunity_id,p.order_id,p.name,p.status,p.starts_at,p.ends_at,p.scope_json,p.original_edition_key,
            x.program,x.venue,x.assigned_user_id,x.assigned_owner_name,org.name AS organization,
            TRIM(CONCAT_WS(\' \',u.name,u.lastname)) AS manager_name' . self::PROJECT_JOIN . self::ORG_JOIN .
            ' LEFT JOIN users u ON u.id=x.assigned_user_id AND u.id_owner=x.id_owner WHERE ' . $this->scope() .
            ' ORDER BY p.starts_at IS NULL,p.starts_at,p.id', $this->scopeParams())->fetchAll(PDO::FETCH_ASSOC);
        $tasks = $this->query("SELECT t.id,t.id_user,t.task_description,t.is_done,t.pmc_task_key,t.pmc_phase,t.pmc_due_date,
            t.pmc_priority,t.pmc_dependencies,t.pmc_blocker,t.pmc_evidence,t.pmc_acceptance,t.pmc_role,
            p.id project_id,p.name project_name,DATE(p.starts_at) event_date,org.name organization,
            TRIM(CONCAT_WS(' ',staff.name,staff.lastname)) assignee" . self::PROJECT_JOIN . self::ORG_JOIN .
            " JOIN orders_team_tasks t ON t.id_owner=p.id_owner AND ((t.pmc_project_id=p.id AND (t.id_order IS NULL OR t.id_order=p.order_id))
                OR (t.pmc_project_id IS NULL AND t.id_order=p.order_id AND EXISTS(SELECT 1 FROM orders ord WHERE ord.id=p.order_id AND ord.id_owner=p.id_owner)))
            LEFT JOIN users staff ON staff.id=t.id_user AND (staff.id_owner=p.id_owner OR staff.id=p.id_owner)
            WHERE " . $this->scope() . ' ORDER BY t.id', $this->scopeParams())->fetchAll(PDO::FETCH_ASSOC);
        $byProject = [];
        foreach ($tasks as $task) {
            $byProject[$task['project_id']][] = $task;
        }

        $metrics = array_fill_keys(['active','upcoming','completed','original','client','overdue','urgent','next'], 0);
        $activeTasks = [];
        $selected = null;
        $listed = [];
        $thisWeek = (new DateTimeImmutable($out['today']))->modify('+7 days')->format('Y-m-d');
        foreach ($projects as $project) {
            $id = $project['id'];
            $project['date'] = substr((string)$project['starts_at'],0,10);
            $project['manager'] = $project['manager_name'] ?: $project['assigned_owner_name'];
            $project['closed'] = in_array(strtoupper($project['status']), ['COMPLETED','CLOSED','ARCHIVED','CANCELLED','CANCELED'], true);
            $project['recap'] = json_decode((string)$project['scope_json'],true)['pmc_recap'] ?? [];
            unset($project['scope_json']);
            if (!$this->direction) { unset($project['recap']['finance']); }
            $schedule = $this->schedule($byProject[$id] ?? [], $out['today'], $thisWeek);
            $project['total'] = count($schedule);
            $project['done'] = count(array_filter($schedule, static fn($t)=>$t['is_done']));
            $project['progress'] = $schedule ? (int)floor(100 * $project['done'] / count($schedule)) : null;
            $project['phase'] = $project['closed'] ? 'Closed' : 'Awaiting schedule';
            $project['milestone'] = null;
            foreach (self::PHASES as $phase) {
                $inPhase = array_filter($schedule, static fn($t)=>$t['pmc_phase']===$phase && !$t['is_done']);
                if ($inPhase && !$project['closed']) {
                    $project['phase'] = $phase;
                    $last = array_reduce($inPhase, static fn($a,$b)=> !$a || strcmp((string)$b['pmc_task_key'], (string)$a['pmc_task_key'])>0 ? $b : $a);
                    $project['milestone'] = $last['pmc_acceptance'];
                    break;
                }
            }
            if (!$project['closed'] && $schedule && $project['done'] === $project['total']) { $project['phase'] = 'Awaiting closeout'; }
            $pending = array_values(array_filter($schedule, static fn($t)=>!$t['is_done']));
            $project['next'] = current(array_filter($pending, static fn($t)=>!$t['blocked'])) ?: ($pending[0] ?? null);
            $project['overdue'] = count(array_filter($pending, static fn($t)=>$t['overdue']));
            $project['blocked'] = count(array_filter($pending, static fn($t)=>$t['blocked']));
            if ($project['closed']) {
                $metrics['completed'] += in_array(strtoupper($project['status']),['COMPLETED','CLOSED'],true) ? 1 : 0;
            } else {
                ++$metrics['active'];
                $metrics['upcoming'] += $project['date'] > $out['today'] ? 1 : 0;
                ++$metrics[$project['program']==='ORIGINAL' ? 'original' : 'client'];
                $metrics['overdue'] += $project['overdue'];
                $metrics['urgent'] += count(array_filter($pending, static fn($t)=>$t['urgent']));
                $metrics['next'] += count(array_filter($pending, static fn($t)=>$t['soon']));
                foreach ($pending as $task) { $activeTasks[] = $task; }
            }
            if ((int)($f['project'] ?? 0) === (int)$id) {
                $selected = $project + ['tasks'=>$schedule];
            }
            if ($this->matchProject($project,$f,$tab)) { $listed[] = $project; }
        }
        $training = $this->query("SELECT t.id,t.id_user,t.task_description,t.is_done,t.pmc_task_key,t.pmc_phase,t.pmc_due_date,
            t.pmc_priority,t.pmc_dependencies,t.pmc_blocker,t.pmc_evidence,t.pmc_acceptance,t.pmc_role,
            NULL project_id,'Staff training' project_name,NULL event_date,'VNV Events' organization,TRIM(CONCAT_WS(' ',u.name,u.lastname)) assignee
            FROM orders_team_tasks t JOIN users u ON u.id=t.id_user AND u.id_owner=t.id_owner
            WHERE t.id_owner=? AND t.id_order IS NULL AND t.pmc_project_id IS NULL AND t.pmc_task_key='PMC_TEAM_TRAINING' AND t.is_done=0"
            . ($this->direction?'':' AND t.id_user=?'),$this->direction?[$this->owner]:[$this->owner,$this->actor])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($this->schedule($training,$out['today'],$thisWeek) as $task) {
            $activeTasks[]=$task;
            $metrics['overdue']+=(int)$task['overdue']; $metrics['urgent']+=(int)$task['urgent']; $metrics['next']+=(int)$task['soon'];
        }
        usort($activeTasks, [self::class,'compareTasks']);
        if ($tab === 'tasks') {
            $scope = $f['scope'] ?? '';
            $activeTasks = array_values(array_filter($activeTasks, static fn($t)=>!in_array($scope,['overdue','urgent','next'],true) || $t[$scope==='next'?'soon':$scope]));
        }
        $out['metric'] = $metrics;
        $out['task_total'] = count($activeTasks);
        $out['tasks'] = $this->page($activeTasks, $tab==='tasks' ? ($f['page'] ?? 1) : 1, $tab==='overview' ? 8 : 40);
        $out['project_total'] = count($listed);
        $out['projects'] = $this->page($listed,$f['page'] ?? 1);
        if ($selected !== null && in_array($tab,['active','past','originals','tasks'],true)) {
            $out['selected'] = $selected;
            $out['selected']['documents'] = $this->query('SELECT label, asset_type FROM commercial_media_assets WHERE id_owner=? AND site_key=? AND project_id=? ORDER BY created_at DESC',
                [$this->owner,$this->site,$selected['id']])->fetchAll(PDO::FETCH_ASSOC);
        }
        if (in_array($tab,['active','originals','tasks'],true)) {
            $out['teams'] = $this->query("SELECT id, TRIM(CONCAT_WS(' ',name,lastname)) name FROM users WHERE id_owner=? AND is_active=1 AND level IN (3,4) ORDER BY name",[$this->owner])->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    private function page(array $rows, mixed $number, int $size = 40): array
    {
        return array_slice($rows, (max(1,min((int)$number, max(1,(int)ceil(count($rows)/$size))))-1)*$size,$size);
    }

    private function matchProject(array $p,array $f,string $tab): bool
    {
        if (($tab==='past') !== $p['closed'] || ($tab==='originals' && $p['program']!=='ORIGINAL')) { return false; }
        $date = $p['date'];
        $scope = $f['scope'] ?? '';
        if ($scope==='completed' && !in_array(strtoupper($p['status']),['CLOSED','COMPLETED'],true)) { return false; }
        if ($scope==='upcoming' && $date <= $this->today()) { return false; }
        if ($scope==='client' && $p['program']==='ORIGINAL' || $scope==='original' && $p['program']!=='ORIGINAL') { return false; }
        return (empty($f['from']) || $date >= $f['from']) && (empty($f['to']) || ($date && $date <= $f['to']))
            && (empty($f['type']) || $p['program']===$f['type'])
            && (empty($f['manager']) || stripos((string)$p['manager'],$f['manager'])!==false)
            && (empty($f['q']) || mb_stripos($p['name'].' '.$p['organization'],$f['q'])!==false);
    }

    private function schedule(array $tasks, string $today,string $week): array
    {
        $done = [];
        foreach ($tasks as $task) {
            if ($task['is_done'] && $task['pmc_task_key']) { $done[$task['pmc_task_key']] = true; }
        }
        foreach ($tasks as &$task) {
            $dependencies = preg_split('/\s+/',trim((string)$task['pmc_dependencies']),-1,PREG_SPLIT_NO_EMPTY);
            $task['waiting_on'] = array_values(array_diff($dependencies,array_keys($done)));
            $task['blocked'] = (bool)($task['waiting_on'] || $task['pmc_blocker']);
            $due = $task['pmc_due_date'];
            $task['overdue'] = $due && $due < $today && !$task['is_done'];
            $task['urgent'] = $task['pmc_priority']==='urgent' && !$task['is_done'];
            $task['soon'] = $due && $due >= $today && $due <= $week && !$task['is_done'];
            $task['event_soon'] = $task['event_date'] && $task['event_date'] >= $today && $task['event_date'] <= $week;
            $task['editable'] = (!$task['is_done'] || ($task['pmc_task_key'] && !$task['pmc_evidence'])) && ($this->direction || (int)$task['id_user']===$this->actor);
        }
        unset($task);
        usort($tasks,[self::class,'compareTasks']);
        return $tasks;
    }

    private static function compareTasks(array $a,array $b): int
    {
        $sort = static fn($t)=>[(int)$t['is_done'],!$t['overdue'],!$t['urgent'],!$t['soon'],
            (bool)$t['blocked'],!$t['event_soon'],$t['pmc_due_date'] ?: '9999',(int)$t['id']];
        return $sort($a) <=> $sort($b);
    }

    private function crm(array $f,bool $detail): array
    {
        $from = ' FROM commercial_opportunities x' . self::ORG_JOIN .
            ' LEFT JOIN commercial_contacts c ON c.id=x.contact_id AND c.id_owner=x.id_owner AND c.site_key=x.site_key WHERE ' . $this->scope();
        $params = $this->scopeParams();
        $out = ['pipeline_counts'=>$this->query("SELECT x.program,x.stage,COUNT(*) total $from GROUP BY x.program,x.stage",$params)->fetchAll(PDO::FETCH_ASSOC)];
        $out['opportunities'] = (int)$this->query("SELECT COUNT(*) $from",$params)->fetchColumn();
        $out['proposal_count'] = (int)$this->query("SELECT COUNT(*) FROM commercial_proposals pr JOIN commercial_opportunities x ON x.id=pr.opportunity_id AND x.id_owner=pr.id_owner AND x.site_key=pr.site_key WHERE " . $this->scope() .
            " AND pr.status IN ('DRAFT','SENT','PENDING','SUBMITTED','IN_REVIEW') AND NOT EXISTS(SELECT 1 FROM commercial_proposals later WHERE later.id_owner=pr.id_owner AND later.site_key=pr.site_key AND later.opportunity_id=pr.opportunity_id AND (later.version_number>pr.version_number OR (later.version_number=pr.version_number AND later.id>pr.id)))",$params)->fetchColumn();
        if (!$detail) { return $out; }
        if (!empty($f['category']) && isset(self::CATEGORIES[$f['category']])) {
            $from .= ' AND ' . self::CATEGORY . '=?'; $params[] = $f['category'];
        }
        if (!empty($f['q'])) {
            $from .= " AND CONCAT_WS(' ',x.title,org.name,c.name,c.email) LIKE ?"; $params[] = '%' . $f['q'] . '%';
        }
        if (($f['scope'] ?? '') === 'proposals') {
            $from .= " AND EXISTS(SELECT 1 FROM commercial_proposals pr WHERE pr.id_owner=x.id_owner AND pr.site_key=x.site_key AND pr.opportunity_id=x.id AND pr.status IN ('DRAFT','SENT','PENDING','SUBMITTED','IN_REVIEW') AND NOT EXISTS(SELECT 1 FROM commercial_proposals later WHERE later.id_owner=pr.id_owner AND later.site_key=pr.site_key AND later.opportunity_id=pr.opportunity_id AND (later.version_number>pr.version_number OR (later.version_number=pr.version_number AND later.id>pr.id))))";
        }
        $out['crm_total'] = (int)$this->query("SELECT COUNT(*) $from",$params)->fetchColumn();
        $offset = 40*(max(1,min((int)($f['page']??1),max(1,(int)ceil($out['crm_total']/40))))-1);
        $out['crm_rows'] = $this->query('SELECT x.id,x.title,x.stage,x.pipeline,x.event_date,org.name organization,c.name contact_name,x.next_follow_up_at,' . self::CATEGORY . " category $from ORDER BY x.next_follow_up_at IS NULL,x.next_follow_up_at,x.id DESC LIMIT 40 OFFSET $offset",$params)->fetchAll(PDO::FETCH_ASSOC);
        if ((int)($f['opportunity']??0)>0) {
            $record = $this->opportunity((int)$f['opportunity'],false);
            $out['record'] = $record;
            $out['record']['can_handoff'] = $this->approved($record);
            foreach (['activities'=>['commercial_activities','activity_type,summary,due_at,created_at'],
                'proposals'=>['commercial_proposals','id,version_number,status,sent_at,accepted_at'],
                'documents'=>['commercial_media_assets','label,asset_type,created_at,storage_path']] as $key=>[$table,$columns]) {
                $order = $key==='proposals' ? 'version_number DESC,id DESC' : 'id DESC';
                $out[$key] = $this->query("SELECT $columns FROM $table WHERE id_owner=? AND site_key=? AND opportunity_id=? ORDER BY $order LIMIT 100",[$this->owner,$this->site,$record['id']])->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($out['documents'] as &$doc) {
                $doc['url']=self::documentUrl((string)$doc['storage_path']);
                unset($doc['storage_path']);
            }
            unset($doc);
        }
        return $out;
    }

    private static function documentUrl(string $value): ?string
    {
        if (!filter_var($value,FILTER_VALIDATE_URL)) { return null; }
        $parts=parse_url($value);
        if (($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) { return null; }
        $host=strtolower($parts['host']??'');
        $allowed=in_array($host,['drive.google.com','docs.google.com','onedrive.live.com','app.asana.com','vnvevents.com'],true)
            || str_ends_with($host,'.sharepoint.com') || str_ends_with($host,'.vnvevents.com');
        return $allowed?$value:null;
    }

    private function originals(int $selectedEdition): array
    {
        $editions = $this->query("SELECT COALESCE(NULLIF(x.event_name,''),'Original Experiences') edition,
            COUNT(DISTINCT COALESCE(CONCAT('c',x.contact_id),CONCAT('o',x.id))) interested,
            COUNT(DISTINCT CASE WHEN x.stage='TICKETED' THEN COALESCE(CONCAT('c',x.contact_id),CONCAT('o',x.id)) END) ticketed
            FROM commercial_opportunities x WHERE x.id_owner=? AND x.site_key=? AND x.program='ORIGINAL' AND x.pipeline='ORIGINAL_WAITLIST' GROUP BY edition ORDER BY edition", [$this->owner,$this->site])->fetchAll(PDO::FETCH_ASSOC);
        $clubs = $this->query("SELECT e.id,e.title,e.starts_at,e.status,e.location,COUNT(r.invited_at) invitations,
            COALESCE(SUM(CASE WHEN r.status='PAID' THEN r.party_size ELSE 0 END),0) tickets,
            COALESCE(SUM(CASE WHEN r.status='PAID' THEN 1 ELSE 0 END),0) buyers,
            COALESCE(SUM((r.principal_checked_at IS NOT NULL)+(r.companion_checked_at IS NOT NULL)),0) attendance" .
            ($this->direction ? ',COALESCE(SUM(r.paid_cents),0) net_cents' : '') .
            " FROM vnv_break_events e LEFT JOIN (vnv_break_registrations r JOIN vnv_break_members m ON m.id=r.member_id)
              ON r.event_id=e.id AND m.owner_id=e.owner_id WHERE e.owner_id=? GROUP BY e.id,e.title,e.starts_at,e.status,e.location ORDER BY e.starts_at DESC",[$this->owner])->fetchAll(PDO::FETCH_ASSOC);
        $interest = (int)$this->query("SELECT COUNT(*) FROM vnv_break_members WHERE owner_id=? AND status='ACTIVE'",[$this->owner])->fetchColumn();
        $linked=$this->query('SELECT p.id,p.original_edition_key' . self::PROJECT_JOIN . ' WHERE '.$this->scope()." AND p.original_edition_key LIKE 'break:%'",$this->scopeParams())->fetchAll(PDO::FETCH_KEY_PAIR);
        $links=array_flip($linked);
        $register=[];
        foreach ($clubs as &$club) {
            $club['project_id']=$links['break:'.$club['id']]??null;
            $club['can_open']=$this->direction || $club['project_id'];
            if ($selectedEdition===(int)$club['id'] && $club['can_open']) {
                $register=$this->query('SELECT r.name,r.companion_name,r.party_size,r.status,r.invited_at,r.paid_at,r.principal_checked_at,r.companion_checked_at FROM vnv_break_events e
                    JOIN vnv_break_registrations r ON r.event_id=e.id JOIN vnv_break_members m ON m.id=r.member_id AND m.owner_id=e.owner_id
                    WHERE e.id=? AND e.owner_id=? ORDER BY r.name,r.id',[$club['id'],$this->owner])->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        unset($club);
        return ['editions'=>$editions,'club_events'=>$clubs,'club_members'=>$interest,'tickets'=>array_sum(array_column($clubs,'tickets')),'guest_register'=>$register];
    }
}
