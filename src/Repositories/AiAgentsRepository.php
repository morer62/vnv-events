<?php

namespace App\Repositories;

use App\Services\AiScheduleService;
use App\Services\AiAgentNotificationService;
use App\Utils\SiteContext;
use RuntimeException;

class AiAgentsRepository
{
    public Connection $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function storageReady(): bool
    {
        try {
            $this->db->query("SHOW TABLES LIKE 'ai_agents'");
            return (bool)$this->db->fetchOne();
        } catch (\Throwable) {
            return false;
        }
    }

    public function allForOwner(int $ownerId): array
    {
        $this->db->query("SELECT a.*,
            (SELECT COUNT(*) FROM ai_agent_runs r WHERE r.id_agent = a.id) AS run_count,
            (SELECT COUNT(*) FROM ai_agent_approvals p WHERE p.id_agent = a.id AND p.status = 'PENDING') AS pending_approvals
            FROM ai_agents a WHERE a.id_owner = :owner AND a.site_key=:site ORDER BY a.category, a.name");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        return $this->db->fetchAll();
    }

    public function find(int $ownerId, string $key): ?object
    {
        $this->db->query("SELECT * FROM ai_agents WHERE id_owner = :owner AND site_key=:site AND agent_key = :agent_key LIMIT 1");
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        $this->db->bind(':agent_key', $key);
        return $this->db->fetchOne() ?: null;
    }

    public function seed(int $ownerId, array $definitions): void
    {
        foreach ($definitions as $key => $definition) {
            $this->db->query("INSERT INTO ai_agents
                (id_owner, site_key, agent_key, name, description, category, status, approval_mode, settings_json)
                VALUES (:owner, :site, :agent_key, :name, :description, :category, :status, 'ALWAYS', :settings)
                ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), category = VALUES(category),
                status = IF(status IN ('DRAFT','SETUP_REQUIRED'), VALUES(status), status)");
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', SiteContext::siteKey());
            $this->db->bind(':agent_key', $key);
            $this->db->bind(':name', $definition['name']);
            $this->db->bind(':description', $definition['description']);
            $this->db->bind(':category', $definition['category']);
            $this->db->bind(':status', $definition['status']);
            $this->db->bind(':settings', json_encode($definition['settings'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->db->execute();
        }
    }

    public function createRun(int $agentId, int $ownerId, string $trigger, ?int $userId, array $input = []): int
    {
        $this->db->query("INSERT INTO ai_agent_runs
            (id_agent, id_owner, site_key, trigger_type, status, input_json, created_by, started_at)
            VALUES (:agent, :owner, :site, :trigger, 'RUNNING', :input, :user, NOW())");
        $this->db->bind(':agent', $agentId);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        $this->db->bind(':trigger', $trigger);
        $this->db->bind(':input', json_encode($input, JSON_UNESCAPED_SLASHES));
        $this->db->bind(':user', $userId);
        $this->db->execute();
        return (int)$this->db->lastId();
    }

    public function finishRun(int $runId, string $status, array $output = [], ?string $error = null): void
    {
        $this->db->query("UPDATE ai_agent_runs SET status = :status, output_json = :output,
            error_message = :error, finished_at = NOW() WHERE id = :id");
        $this->db->bind(':status', $status);
        $this->db->bind(':output', json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->db->bind(':error', $error);
        $this->db->bind(':id', $runId);
        $this->db->execute();
    }

    public function touchAgent(int $agentId, string $status): void
    {
        $this->db->query("UPDATE ai_agents SET last_run_at = NOW(), status = :status WHERE id = :id");
        $this->db->bind(':status', $status);
        $this->db->bind(':id', $agentId);
        $this->db->execute();
    }

    public function recentRuns(int $agentId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $this->db->query("SELECT * FROM ai_agent_runs WHERE id_agent = :agent ORDER BY id DESC LIMIT {$limit}");
        $this->db->bind(':agent', $agentId);
        return $this->db->fetchAll();
    }

    public function pendingApprovals(int $ownerId, ?int $agentId = null): array
    {
        $sql = "SELECT p.*, a.name AS agent_name, a.agent_key FROM ai_agent_approvals p
            JOIN ai_agents a ON a.id = p.id_agent WHERE p.id_owner = :owner AND p.site_key=:site";
        if ($agentId) {
            $sql .= " AND p.id_agent = :agent";
        }
        $sql .= " ORDER BY p.id DESC LIMIT 100";
        $this->db->query($sql);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        if ($agentId) $this->db->bind(':agent', $agentId);
        return $this->db->fetchAll();
    }

    public function approvalInbox(int $ownerId,string $tab='all',string $status='PENDING',string $search='',int $page=1,int $perPage=30): array
    {
        $allowedStatus=['PENDING','APPROVED','EXECUTED','REJECTED','REVISION_REQUESTED'];if(!in_array($status,$allowedStatus,true))$status='PENDING';
        $groups=[
            'social'=>['PUBLISH_SOCIAL','PUBLISH_CAROUSEL','REVIEW_SHORT_VIDEO'],
            'estimates'=>['CREATE_ESTIMATE_DRAFT'],
            'reminders'=>['SEND_FOLLOW_UP','SEND_REVIEW_REQUEST','SEND_CONCIERGE_RESPONSE','SEND_META_RESPONSE'],
            'content'=>['PUBLISH_ARTICLE','REVIEW_CONTENT_REFRESH','REVIEW_POST_EVENT_RECAP'],
            'operations'=>['REVIEW_ORDER_ISSUES','REVIEW_OPERATIONAL_RISK','REVIEW_EVENT_BRIEF','REVIEW_QUALIFIED_LEAD'],
        ];
        $perPage=max(10,min(100,$perPage));$page=max(1,$page);$offset=($page-1)*$perPage;$search=trim($search);
        $sql="SELECT p.*,a.name agent_name,a.agent_key FROM ai_agent_approvals p JOIN ai_agents a ON a.id=p.id_agent WHERE p.id_owner=:owner AND p.site_key=:site AND p.status=:status";
        if(isset($groups[$tab])){$quoted=implode(',',array_map(fn($v)=>"'".str_replace("'","''",$v)."'",$groups[$tab]));$sql.=" AND p.action_type IN ({$quoted})";}
        if($search!=='')$sql.=" AND (p.title LIKE :search OR a.name LIKE :search OR p.action_type LIKE :search)";
        $sql.=" ORDER BY p.id DESC LIMIT {$perPage} OFFSET {$offset}";$this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->bind(':status',$status);if($search!=='')$this->db->bind(':search','%'.$search.'%');return $this->db->fetchAll();
    }

    public function approvalInboxTotal(int $ownerId,string $tab='all',string $status='PENDING',string $search=''): int
    {
        $allowedStatus=['PENDING','APPROVED','EXECUTED','REJECTED','REVISION_REQUESTED'];if(!in_array($status,$allowedStatus,true))$status='PENDING';
        $groups=['social'=>['PUBLISH_SOCIAL','PUBLISH_CAROUSEL','REVIEW_SHORT_VIDEO'],'estimates'=>['CREATE_ESTIMATE_DRAFT'],'reminders'=>['SEND_FOLLOW_UP','SEND_REVIEW_REQUEST','SEND_CONCIERGE_RESPONSE','SEND_META_RESPONSE'],'content'=>['PUBLISH_ARTICLE','REVIEW_CONTENT_REFRESH','REVIEW_POST_EVENT_RECAP'],'operations'=>['REVIEW_ORDER_ISSUES','REVIEW_OPERATIONAL_RISK','REVIEW_EVENT_BRIEF','REVIEW_QUALIFIED_LEAD']];
        $search=trim($search);$sql="SELECT COUNT(*) total FROM ai_agent_approvals p JOIN ai_agents a ON a.id=p.id_agent WHERE p.id_owner=:owner AND p.site_key=:site AND p.status=:status";
        if(isset($groups[$tab])){$quoted=implode(',',array_map(fn($v)=>"'".str_replace("'","''",$v)."'",$groups[$tab]));$sql.=" AND p.action_type IN ({$quoted})";}
        if($search!=='')$sql.=" AND (p.title LIKE :search OR a.name LIKE :search OR p.action_type LIKE :search)";
        $this->db->query($sql);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->bind(':status',$status);if($search!=='')$this->db->bind(':search','%'.$search.'%');$row=$this->db->fetchOne();return (int)($row->total??0);
    }

    public function approvalCounts(int $ownerId): array
    {
        $this->db->query("SELECT
          SUM(status='PENDING') pending,
          SUM(status='APPROVED') approved,
          SUM(status='EXECUTED') executed,
          SUM(status='REJECTED') rejected
          FROM ai_agent_approvals WHERE id_owner=:owner AND site_key=:site");$this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$row=$this->db->fetchOne();
        return $row?(array)$row:[];
    }

    public function findApproval(int $ownerId, int $approvalId): ?object
    {
        $this->db->query("SELECT p.*,a.agent_key,a.name AS agent_name,a.description AS agent_description
            FROM ai_agent_approvals p JOIN ai_agents a ON a.id=p.id_agent
            WHERE p.id=:id AND p.id_owner=:owner AND p.site_key=:site LIMIT 1");
        $this->db->bind(':id',$approvalId); $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());
        return $this->db->fetchOne() ?: null;
    }

    public function approvalHistory(int $ownerId, int $runId, string $actionType): array
    {
        $this->db->query("SELECT * FROM ai_agent_approvals WHERE id_owner=:owner AND site_key=:site AND id_run=:run
            AND action_type=:action ORDER BY id DESC");
        $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey()); $this->db->bind(':run',$runId); $this->db->bind(':action',$actionType);
        return $this->db->fetchAll();
    }

    public function requestApprovalRevision(int $ownerId, object $approval, int $reviewerId, string $note, array $revisedPayload): int
    {
        $this->db->query("UPDATE ai_agent_approvals SET status='REVISION_REQUESTED',reviewed_by=:reviewer,
            review_note=:note,reviewed_at=NOW() WHERE id=:id AND id_owner=:owner AND site_key=:site AND status='PENDING'");
        $this->db->bind(':reviewer',$reviewerId); $this->db->bind(':note',$note);
        $this->db->bind(':id',$approval->id); $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey()); $this->db->execute();
        $revisedPayload['_revision']=[
            'previous_approval_id'=>(int)$approval->id,
            'instructions'=>$note,
            'created_at'=>date('c'),
        ];
        return $this->createApproval((int)$approval->id_run,(int)$approval->id_agent,$ownerId,(string)$approval->action_type,(string)$approval->title.' — revised',$revisedPayload,$reviewerId);
    }

    public function updateApprovalPayload(int $ownerId, int $approvalId, array $payload): void
    {
        $this->db->query("UPDATE ai_agent_approvals SET payload_json=:payload WHERE id=:id AND id_owner=:owner AND site_key=:site AND status='PENDING'");
        $this->db->bind(':payload',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $this->db->bind(':id',$approvalId); $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey()); $this->db->execute();
    }

    public function createApproval(int $runId, int $agentId, int $ownerId, string $action, string $title, array $payload, ?int $userId): int
    {
        $this->db->query("SELECT id FROM ai_agent_approvals WHERE id_agent=:agent AND id_owner=:owner AND site_key=:site
            AND action_type=:action AND title=:title AND status IN ('PENDING','APPROVED','PROCESSING') ORDER BY id DESC LIMIT 1");
        $this->db->bind(':agent',$agentId);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->bind(':action',$action);$this->db->bind(':title',$title);
        $existing=$this->db->fetchOne();if($existing){
            $this->db->query("UPDATE ai_agent_approvals SET id_run=:run,payload_json=:payload,requested_by=:user,created_at=NOW(),review_note=NULL WHERE id=:id AND id_owner=:owner AND site_key=:site");
            $this->db->bind(':run',$runId);$this->db->bind(':payload',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$this->db->bind(':user',$userId);$this->db->bind(':id',(int)$existing->id);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->execute();
            return (int)$existing->id;
        }
        $this->db->query("INSERT INTO ai_agent_approvals
            (id_run, id_agent, id_owner, site_key, action_type, title, payload_json, requested_by)
            VALUES (:run, :agent, :owner, :site, :action, :title, :payload, :user)");
        $this->db->bind(':run', $runId);
        $this->db->bind(':agent', $agentId);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        $this->db->bind(':action', $action);
        $this->db->bind(':title', $title);
        $this->db->bind(':payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->db->bind(':user', $userId);
        $this->db->execute();
        $id=(int)$this->db->lastId();
        if($userId){
            try{(new AiAgentNotificationService())->approvalReady($userId,$id,$title);}catch(\Throwable){}
        }
        return $id;
    }

    public function reviewApproval(int $ownerId, int $approvalId, string $status, int $reviewerId, string $note): void
    {
        if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new RuntimeException('Invalid approval decision.');
        }
        $this->db->query("UPDATE ai_agent_approvals SET status = :status, reviewed_by = :reviewer,
            review_note = :note, reviewed_at = NOW()
            WHERE id = :id AND id_owner = :owner AND site_key=:site AND status = 'PENDING'");
        $this->db->bind(':status', $status);
        $this->db->bind(':reviewer', $reviewerId);
        $this->db->bind(':note', $note);
        $this->db->bind(':id', $approvalId);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        $this->db->execute();
        $this->db->query("UPDATE ai_agent_runs r
            JOIN ai_agent_approvals decided ON decided.id_run=r.id
            SET r.status='COMPLETED',r.finished_at=COALESCE(r.finished_at,NOW())
            WHERE decided.id=:approval AND decided.id_owner=:owner AND decided.site_key=:site
              AND NOT EXISTS(SELECT 1 FROM ai_agent_approvals pending WHERE pending.id_run=r.id AND pending.status IN ('PENDING','PROCESSING'))");
        $this->db->bind(':approval',$approvalId);$this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey());$this->db->execute();
    }

    public function rotateWebhook(int $ownerId, string $key): string
    {
        $token = bin2hex(random_bytes(24));
        $this->db->query("UPDATE ai_agents SET webhook_token_hash = :hash, webhook_token_hint = :hint
            WHERE id_owner = :owner AND site_key=:site AND agent_key = :agent_key");
        $this->db->bind(':hash', hash('sha256', $token));
        $this->db->bind(':hint', substr($token, -8));
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', SiteContext::siteKey());
        $this->db->bind(':agent_key', $key);
        $this->db->execute();
        return $token;
    }

    public function updateConfiguration(int $ownerId, string $key, array $data): void
    {
        $status = in_array($data['status'] ?? '', ['DRAFT','ACTIVE','PAUSED','ERROR','SETUP_REQUIRED'], true) ? $data['status'] : 'DRAFT';
        $approval = in_array($data['approval_mode'] ?? '', ['ALWAYS','IMPORTANT','NEVER'], true) ? $data['approval_mode'] : 'ALWAYS';
        $scheduleEnabled = !empty($data['schedule_enabled']) ? 1 : 0;
        $schedule = trim((string)($data['schedule_expression'] ?? ''));
        $nextRun = $scheduleEnabled ? (new AiScheduleService())->next($schedule)->format('Y-m-d H:i:s') : null;
        $this->db->query("UPDATE ai_agents SET status=:status,approval_mode=:approval,
          schedule_enabled=:enabled,schedule_expression=:schedule,next_run_at=:next_run
          WHERE id_owner=:owner AND site_key=:site AND agent_key=:agent_key");
        $this->db->bind(':status',$status); $this->db->bind(':approval',$approval);
        $this->db->bind(':enabled',$scheduleEnabled); $this->db->bind(':schedule',$schedule ?: null);
        $this->db->bind(':next_run',$nextRun); $this->db->bind(':owner',$ownerId);$this->db->bind(':site',SiteContext::siteKey()); $this->db->bind(':agent_key',$key);
        $this->db->execute();
    }

    public function dueScheduledAgents(): array
    {
        $this->db->query("SELECT * FROM ai_agents WHERE site_key=:site AND status='ACTIVE' AND schedule_enabled=1
          AND (next_run_at IS NULL OR next_run_at<=NOW()) ORDER BY id LIMIT 50");
        $this->db->bind(':site',SiteContext::siteKey());
        return $this->db->fetchAll();
    }

    public function scheduleNextDay(int $agentId): void
    {
        $this->db->query("UPDATE ai_agents SET next_run_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=:id");
        $this->db->bind(':id',$agentId); $this->db->execute();
    }

    public function scheduleNext(int $agentId,?string $expression): void
    {
        $next=(new AiScheduleService())->next($expression)->format('Y-m-d H:i:s');
        $this->db->query("UPDATE ai_agents SET next_run_at=:next WHERE id=:id");
        $this->db->bind(':next',$next);$this->db->bind(':id',$agentId);$this->db->execute();
    }

    public function claimScheduled(int $agentId,?string $expression): bool
    {
        $next=(new AiScheduleService())->next($expression)->format('Y-m-d H:i:s');
        $this->db->query("UPDATE ai_agents SET next_run_at=:next WHERE id=:id AND status='ACTIVE' AND schedule_enabled=1 AND (next_run_at IS NULL OR next_run_at<=NOW())");
        $this->db->bind(':next',$next);$this->db->bind(':id',$agentId);$this->db->execute();
        return $this->db->rowCount()===1;
    }
}
