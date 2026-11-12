<?php

namespace App\Repositories;

use App\Services\CommercialFinanceService;

final class CommercialPartnershipRepository
{
    public Connection $db;

    private const PIPELINES = [
        'NONPROFIT' => ['pipeline' => 'NONPROFIT_APPLICATION', 'stage' => 'RECEIVED'],
        'CORPORATE' => ['pipeline' => 'CORPORATE', 'stage' => 'LEAD'],
        'SPONSORSHIP' => ['pipeline' => 'SPONSORSHIP', 'stage' => 'INTRO_CONSENTED'],
        'ACTIVATION' => ['pipeline' => 'ACTIVATION', 'stage' => 'BRIEF'],
        'ORIGINAL' => ['pipeline' => 'ORIGINAL_WAITLIST', 'stage' => 'WAITLIST'],
    ];

    private const ALLOWED_STAGES = [
        'NONPROFIT_APPLICATION' => ['RECEIVED', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE', 'CLOSED'],
        'CORPORATE' => ['LEAD', 'DISCOVERY', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'],
        'SPONSORSHIP' => ['INTRO_CONSENTED', 'CONTACTED', 'PROPOSAL', 'CONTRACTED', 'PAID', 'FULFILLED'],
        'ACTIVATION' => ['BRIEF', 'DESIGN', 'APPROVED', 'BUILD', 'LIVE', 'RECAP'],
        'ORIGINAL_WAITLIST' => ['WAITLIST', 'QUALIFIED', 'INVITED', 'TICKETED'],
    ];

    public function __construct(?Connection $connection = null)
    {
        $this->db = $connection ?? new Connection();
    }

    public static function allowedStages(): array
    {
        return self::ALLOWED_STAGES;
    }

    public function createPublicOpportunity(int $ownerId, string $siteKey, string $program, array $data): int
    {
        $program = strtoupper($program);
        if (!isset(self::PIPELINES[$program])) {
            throw new \InvalidArgumentException('Unsupported partnership program.');
        }

        $siteKey = preg_replace('/[^a-z0-9_-]/', '', strtolower($siteKey)) ?: 'vnvevents';
        $pipeline = self::PIPELINES[$program];
        $organizationName = trim((string)($data['organization_name'] ?? $data['brand_name'] ?? ''));
        $contactName = trim((string)($data['contact_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));

        $this->db->beginTransaction();
        try {
            $organizationId = $this->findOrCreateOrganization($ownerId, $siteKey, $organizationName, $data, $program);
            $contactId = $this->findOrCreateContact($ownerId, $siteKey, $organizationId, $contactName, $email, $data);

            $this->db->query(
                'SELECT id FROM commercial_opportunities
                 WHERE id_owner=:owner AND site_key=:site AND program=:program AND contact_id=:contact
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 ORDER BY id DESC LIMIT 1'
            );
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':program', $program);
            $this->db->bind(':contact', $contactId);
            $duplicate = $this->db->fetchOne();
            if ($duplicate) {
                $this->db->commit();
                return (int)$duplicate->id;
            }

            $eventName = trim((string)($data['event_name'] ?? $data['event_organizer'] ?? ''));
            $titleBase = $eventName !== '' ? $eventName : ($organizationName !== '' ? $organizationName : $contactName);
            $title = mb_substr(ucwords(strtolower($program)) . ' · ' . $titleBase, 0, 220);
            $services = $data['requested_services'] ?? [];
            if (!is_array($services)) {
                $services = [$services];
            }
            $services = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $services)));

            $utm = [];
            foreach (['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_url'] as $key) {
                if (!empty($data[$key])) {
                    $utm[$key] = mb_substr(trim((string)$data[$key]), 0, 500);
                }
            }

            $this->db->query(
                'INSERT INTO commercial_opportunities
                (id_owner,site_key,organization_id,contact_id,program,pipeline,stage,title,event_name,event_date,venue,city,guest_count,budget_range,requested_services,assigned_owner_name,source,utm_json,payload_json,next_follow_up_at)
                VALUES
                (:owner,:site,:organization,:contact,:program,:pipeline,:stage,:title,:event_name,:event_date,:venue,:city,:guest_count,:budget,:services,:assigned_owner,:source,:utm,:payload,DATE_ADD(NOW(), INTERVAL 1 DAY))'
            );
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':organization', $organizationId ?: null);
            $this->db->bind(':contact', $contactId);
            $this->db->bind(':program', $program);
            $this->db->bind(':pipeline', $pipeline['pipeline']);
            $this->db->bind(':stage', $pipeline['stage']);
            $this->db->bind(':title', $title);
            $this->db->bind(':event_name', $eventName ?: null);
            $this->db->bind(':event_date', $this->dateOrNull($data['event_date'] ?? null));
            $this->db->bind(':venue', $this->short($data['venue'] ?? null, 255));
            $this->db->bind(':city', $this->short($data['city'] ?? null, 120));
            $this->db->bind(':guest_count', ($data['guest_count'] ?? 0) > 0 ? (int)$data['guest_count'] : null);
            $this->db->bind(':budget', $this->short($data['budget_range'] ?? null, 120));
            $this->db->bind(':services', $services ? implode(', ', $services) : null);
            $this->db->bind(':assigned_owner', $program === 'NONPROFIT' ? 'Oriana' : null);
            $this->db->bind(':source', $this->short($data['source'] ?? 'website', 80));
            $this->db->bind(':utm', $utm ? json_encode($utm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
            $this->db->bind(':payload', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->db->execute();
            $opportunityId = (int)$this->db->lastId();

            $this->recordConsent($ownerId, $siteKey, $contactId, $opportunityId, $data);
            $this->recordActivity($ownerId, $siteKey, $opportunityId, 'FORM_SUBMITTED', 'Public ' . strtolower($program) . ' intake received.', ['source' => $data['source'] ?? 'website']);

            if ($program === 'NONPROFIT') {
                $this->db->query('INSERT INTO commercial_support_grants(id_owner,site_key,opportunity_id,status) VALUES(:owner,:site,:opportunity,\'UNDER_REVIEW\')');
                $this->db->bind(':owner', $ownerId);
                $this->db->bind(':site', $siteKey);
                $this->db->bind(':opportunity', $opportunityId);
                $this->db->execute();
            } elseif ($program === 'ACTIVATION') {
                $this->db->query('INSERT INTO commercial_activation_orders(id_owner,site_key,opportunity_id,status,activation_type,dimensions,approvals_json,deliverables_json) VALUES(:owner,:site,:opportunity,\'BRIEF\',:type,:dimensions,:approvals,:deliverables)');
                $this->db->bind(':owner', $ownerId);
                $this->db->bind(':site', $siteKey);
                $this->db->bind(':opportunity', $opportunityId);
                $this->db->bind(':type', $this->short($data['activation_type'] ?? null, 150));
                $this->db->bind(':dimensions', $this->short($data['dimensions'] ?? null, 150));
                $this->db->bind(':approvals', json_encode(['approvals' => $data['approval_requirements'] ?? null, 'artwork_owner' => $data['artwork_owner'] ?? null]));
                $this->db->bind(':deliverables', json_encode($data['deliverables'] ?? null));
                $this->db->execute();
            } elseif ($program === 'SPONSORSHIP') {
                $this->db->query('INSERT INTO commercial_sponsor_deals(id_owner,site_key,opportunity_id,status) VALUES(:owner,:site,:opportunity,\'INTRO_CONSENTED\')');
                $this->db->bind(':owner', $ownerId);
                $this->db->bind(':site', $siteKey);
                $this->db->bind(':opportunity', $opportunityId);
                $this->db->execute();
            }

            $this->db->commit();
            return $opportunityId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function dashboard(int $ownerId, string $siteKey, array $filters = []): array
    {
        $conditions = ['o.id_owner=:owner', 'o.site_key=:site'];
        $params = [':owner' => $ownerId, ':site' => $siteKey];
        if (!empty($filters['pipeline'])) {
            $conditions[] = 'o.pipeline=:pipeline';
            $params[':pipeline'] = $filters['pipeline'];
        }
        if (!empty($filters['stage'])) {
            $conditions[] = 'o.stage=:stage';
            $params[':stage'] = $filters['stage'];
        }
        if (!empty($filters['q'])) {
            $conditions[] = '(o.title LIKE :q OR c.email LIKE :q OR c.name LIKE :q OR org.name LIKE :q)';
            $params[':q'] = '%' . trim((string)$filters['q']) . '%';
        }
        if (!empty($filters['assigned_user_id'])) {
            $conditions[] = 'o.assigned_user_id=:assigned_user_id';
            $params[':assigned_user_id'] = (int)$filters['assigned_user_id'];
        }

        $this->db->query(
            'SELECT o.*,c.name contact_name,c.email,c.phone,org.name organization_name
             FROM commercial_opportunities o
             LEFT JOIN commercial_contacts c ON c.id=o.contact_id
             LEFT JOIN commercial_organizations org ON org.id=o.organization_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY (o.next_follow_up_at IS NOT NULL AND o.next_follow_up_at <= NOW()) DESC,o.created_at DESC LIMIT 250'
        );
        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }
        $items = $this->db->fetchAll();

        $this->db->query('SELECT pipeline,stage,COUNT(*) total FROM commercial_opportunities WHERE id_owner=:owner AND site_key=:site GROUP BY pipeline,stage ORDER BY pipeline,stage');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        return ['items' => $items, 'counts' => $this->db->fetchAll(), 'stages' => self::ALLOWED_STAGES];
    }

    public function updateStage(int $ownerId, string $siteKey, int $opportunityId, string $stage, ?int $actorUserId = null): bool
    {
        $this->db->query('SELECT pipeline,stage FROM commercial_opportunities WHERE id_owner=:owner AND site_key=:site AND id=:id');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':id', $opportunityId);
        $current = $this->db->fetchOne();
        if (!$current || !in_array($stage, self::ALLOWED_STAGES[$current->pipeline] ?? [], true)) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $this->db->query('UPDATE commercial_opportunities SET stage=:stage,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site AND id=:id');
            $this->db->bind(':stage', $stage);
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':id', $opportunityId);
            $this->db->execute();
            $this->recordActivity($ownerId, $siteKey, $opportunityId, 'STAGE_CHANGED', 'Stage changed from ' . $current->stage . ' to ' . $stage . '.', [], $actorUserId);
            $this->db->query('INSERT INTO commercial_audit_logs(id_owner,site_key,entity_type,entity_id,action,before_json,after_json,actor_user_id) VALUES(:owner,:site,\'opportunity\',:id,\'STAGE_CHANGED\',:before,:after,:actor)');
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':id', $opportunityId);
            $this->db->bind(':before', json_encode(['stage' => $current->stage]));
            $this->db->bind(':after', json_encode(['stage' => $stage]));
            $this->db->bind(':actor', $actorUserId);
            $this->db->execute();
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function assignOpportunity(int $ownerId, string $siteKey, int $opportunityId, int $assignedUserId, int $actorUserId): void
    {
        $opportunity = $this->assertOpportunity($ownerId, $siteKey, $opportunityId);
        $this->db->query('SELECT id,TRIM(CONCAT_WS(\' \',name,lastname)) name FROM users WHERE id_owner=:owner AND id=:user AND level IN(3,4) AND is_active=1 LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':user', $assignedUserId);
        $assignee = $this->db->fetchOne();
        if (!$assignee) {
            throw new \InvalidArgumentException('The selected team member is not active or cannot own commercial opportunities.');
        }
        $this->db->query('UPDATE commercial_opportunities SET assigned_user_id=:user,assigned_owner_name=:name,updated_at=NOW() WHERE id_owner=:owner AND site_key=:site AND id=:id');
        $this->db->bind(':user', (int)$assignee->id);
        $this->db->bind(':name', mb_substr((string)$assignee->name, 0, 120));
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':id', $opportunityId);
        $this->db->execute();
        $this->audit($ownerId, $siteKey, 'opportunity', $opportunityId, 'ASSIGNED', ['assigned_user_id' => $opportunity->assigned_user_id ?? null], ['assigned_user_id' => (int)$assignee->id, 'assigned_owner_name' => (string)$assignee->name], $actorUserId);
        $this->recordActivity($ownerId, $siteKey, $opportunityId, 'OWNER_ASSIGNED', 'Direction assigned the opportunity to ' . (string)$assignee->name . '.', ['assigned_user_id' => (int)$assignee->id], $actorUserId);
    }

    public function teamMembers(int $ownerId): array
    {
        $this->db->query('SELECT id,TRIM(CONCAT_WS(\' \',name,lastname)) name,email,level FROM users WHERE id_owner=:owner AND level IN(3,4) AND is_active=1 ORDER BY name,email');
        $this->db->bind(':owner', $ownerId);
        return $this->db->fetchAll();
    }

    public function approveSupportGrant(int $ownerId, string $siteKey, int $opportunityId, array $grant, int $actorUserId): void
    {
        $validated = (new CommercialFinanceService())->validateSupportGrant(
            (float)($grant['consultation_hours'] ?? 0),
            (float)($grant['discount_food_staff_percent'] ?? 0),
            (float)($grant['discount_decor_percent'] ?? 0),
            (string)($grant['approved_scope'] ?? '')
        );
        $this->assertOpportunity($ownerId, $siteKey, $opportunityId, 'NONPROFIT_APPLICATION');

        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT id,status,consultation_hours,discount_food_staff_percent,discount_decor_percent,approved_scope FROM commercial_support_grants WHERE id_owner=:owner AND site_key=:site AND opportunity_id=:opportunity LIMIT 1');
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':opportunity', $opportunityId);
            $before = $this->db->fetchOne();

            if ($before) {
                $this->db->query('UPDATE commercial_support_grants SET status=\'APPROVED\',consultation_hours=:hours,discount_food_staff_percent=:food,discount_decor_percent=:decor,approved_scope=:scope,approved_by=:actor,approved_at=NOW(),updated_at=NOW() WHERE id=:id');
                $this->db->bind(':id', (int)$before->id);
            } else {
                $this->db->query('INSERT INTO commercial_support_grants(id_owner,site_key,opportunity_id,status,consultation_hours,discount_food_staff_percent,discount_decor_percent,approved_scope,approved_by,approved_at) VALUES(:owner,:site,:opportunity,\'APPROVED\',:hours,:food,:decor,:scope,:actor,NOW())');
                $this->db->bind(':owner', $ownerId);
                $this->db->bind(':site', $siteKey);
                $this->db->bind(':opportunity', $opportunityId);
            }
            $this->db->bind(':hours', $validated['consultation_hours']);
            $this->db->bind(':food', $validated['discount_food_staff_percent']);
            $this->db->bind(':decor', $validated['discount_decor_percent']);
            $this->db->bind(':scope', $validated['approved_scope']);
            $this->db->bind(':actor', $actorUserId);
            $this->db->execute();
            $this->audit($ownerId, $siteKey, 'support_grant', $opportunityId, 'APPROVED', $before ? (array)$before : null, $validated, $actorUserId);
            $this->recordActivity($ownerId, $siteKey, $opportunityId, 'SUPPORT_PLAN_APPROVED', 'Direction approved the nonprofit support plan.', $validated, $actorUserId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function recordCommission(int $ownerId, string $siteKey, int $opportunityId, string $sourceType, string $sourceReference, float $collectedAmount, int $actorUserId): array
    {
        $opportunity = $this->assertOpportunity($ownerId, $siteKey, $opportunityId);
        $sourceType = strtoupper(trim($sourceType));
        $allowedPipelines = [
            'CORPORATE_FEE' => ['CORPORATE'],
            'SPONSORSHIP_FEE' => ['SPONSORSHIP'],
            'ORIGINAL_CONTRIBUTION' => ['ORIGINAL_WAITLIST'],
        ];
        if (!in_array($opportunity->pipeline, $allowedPipelines[$sourceType] ?? [], true)) {
            throw new \InvalidArgumentException('The commission source does not match this opportunity pipeline.');
        }
        $sourceReference = trim($sourceReference);
        if ($sourceReference === '') {
            throw new \InvalidArgumentException('A unique collected-payment reference is required.');
        }
        $calculation = (new CommercialFinanceService())->calculateCommission($sourceType, $collectedAmount);

        $this->db->beginTransaction();
        try {
            $this->db->query('INSERT INTO commercial_commissions(id_owner,site_key,opportunity_id,source_type,source_reference,eligible_collected_amount,oriana_percent,florida_percent,vnv_reserve_percent,status) VALUES(:owner,:site,:opportunity,:type,:reference,:eligible,:oriana,:florida,:reserve,\'PENDING_APPROVAL\')');
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':opportunity', $opportunityId);
            $this->db->bind(':type', $calculation['source_type']);
            $this->db->bind(':reference', mb_substr($sourceReference, 0, 191));
            $this->db->bind(':eligible', $calculation['eligible_amount']);
            $this->db->bind(':oriana', $calculation['oriana_percent']);
            $this->db->bind(':florida', $calculation['florida_percent']);
            $this->db->bind(':reserve', $calculation['vnv_reserve_percent']);
            $this->db->execute();
            $commissionId = (int)$this->db->lastId();
            $this->audit($ownerId, $siteKey, 'commission', $commissionId, 'CREATED_PENDING_APPROVAL', null, $calculation + ['source_reference' => $sourceReference], $actorUserId);
            $this->recordActivity($ownerId, $siteKey, $opportunityId, 'COMMISSION_RECORDED', 'An eligible collected fee was recorded for Direction approval.', ['commission_id' => $commissionId, 'source_reference' => $sourceReference], $actorUserId);
            $this->db->commit();
            return $calculation + ['id' => $commissionId];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function linkInvoice(int $ownerId, string $siteKey, int $opportunityId, int $invoiceId, int $actorUserId): void
    {
        $this->assertOpportunity($ownerId, $siteKey, $opportunityId);
        $this->db->query('SELECT id FROM invoices WHERE id_owner=:owner AND id=:invoice LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':invoice', $invoiceId);
        if (!$this->db->fetchOne()) {
            throw new \InvalidArgumentException('That invoice does not belong to this VNV account.');
        }
        $this->db->query('INSERT IGNORE INTO commercial_invoice_links(id_owner,site_key,opportunity_id,invoice_id) VALUES(:owner,:site,:opportunity,:invoice)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':opportunity', $opportunityId);
        $this->db->bind(':invoice', $invoiceId);
        $this->db->execute();
        $this->audit($ownerId, $siteKey, 'opportunity', $opportunityId, 'INVOICE_LINKED', null, ['invoice_id' => $invoiceId], $actorUserId);
    }

    public function approveCommission(int $ownerId, string $siteKey, int $commissionId, int $actorUserId): void
    {
        $this->db->query('SELECT id,opportunity_id,status,source_type,source_reference,eligible_collected_amount FROM commercial_commissions WHERE id_owner=:owner AND site_key=:site AND id=:id LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':id', $commissionId);
        $commission = $this->db->fetchOne();
        if (!$commission || $commission->status !== 'PENDING_APPROVAL') {
            throw new \InvalidArgumentException('Only a pending commission record can be approved.');
        }
        $this->db->query('UPDATE commercial_commissions SET status=\'APPROVED\',approved_by=:actor,approved_at=NOW() WHERE id_owner=:owner AND site_key=:site AND id=:id');
        $this->db->bind(':actor', $actorUserId);
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':id', $commissionId);
        $this->db->execute();
        $this->audit($ownerId, $siteKey, 'commission', $commissionId, 'APPROVED', (array)$commission, ['status' => 'APPROVED'], $actorUserId);
        $this->recordActivity($ownerId, $siteKey, (int)$commission->opportunity_id, 'COMMISSION_APPROVED', 'Direction approved the eligible commission split.', ['commission_id' => $commissionId], $actorUserId);
    }

    public function financeSnapshot(int $ownerId, string $siteKey): array
    {
        $this->db->query('SELECT sg.*,o.title FROM commercial_support_grants sg JOIN commercial_opportunities o ON o.id=sg.opportunity_id WHERE sg.id_owner=:owner AND sg.site_key=:site AND sg.status=\'APPROVED\' ORDER BY sg.updated_at DESC LIMIT 100');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $grants = $this->db->fetchAll();
        $this->db->query('SELECT c.*,o.title FROM commercial_commissions c JOIN commercial_opportunities o ON o.id=c.opportunity_id WHERE c.id_owner=:owner AND c.site_key=:site ORDER BY c.created_at DESC LIMIT 100');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $commissions = $this->db->fetchAll();
        $invoiceLinks = [];
        try {
            $this->db->query('SELECT l.*,o.title,i.invoice_number FROM commercial_invoice_links l JOIN commercial_opportunities o ON o.id=l.opportunity_id LEFT JOIN invoices i ON i.id=l.invoice_id WHERE l.id_owner=:owner AND l.site_key=:site ORDER BY l.created_at DESC LIMIT 100');
            $this->db->bind(':owner', $ownerId);
            $this->db->bind(':site', $siteKey);
            $invoiceLinks = $this->db->fetchAll();
        } catch (\Throwable $e) {
            error_log('Commercial CRM invoice-link snapshot unavailable: ' . $e->getMessage());
        }
        return ['grants' => $grants, 'commissions' => $commissions, 'invoice_links' => $invoiceLinks];
    }

    private function findOrCreateOrganization(int $ownerId, string $siteKey, string $name, array $data, string $program): ?int
    {
        if ($name === '') {
            return null;
        }
        $this->db->query('SELECT id FROM commercial_organizations WHERE id_owner=:owner AND site_key=:site AND name=:name LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':name', $name);
        $row = $this->db->fetchOne();
        if ($row) {
            return (int)$row->id;
        }
        $this->db->query('INSERT INTO commercial_organizations(id_owner,site_key,name,website,legal_status,ein,organization_type) VALUES(:owner,:site,:name,:website,:legal_status,:ein,:type)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':name', mb_substr($name, 0, 191));
        $this->db->bind(':website', $this->short($data['website'] ?? null, 255));
        $this->db->bind(':legal_status', $this->short($data['legal_status'] ?? null, 120));
        $this->db->bind(':ein', $this->short($data['ein'] ?? null, 40));
        $this->db->bind(':type', $program === 'NONPROFIT' ? 'nonprofit' : (in_array($program, ['ACTIVATION', 'SPONSORSHIP'], true) ? 'brand' : 'prospect'));
        $this->db->execute();
        return (int)$this->db->lastId();
    }

    private function findOrCreateContact(int $ownerId, string $siteKey, ?int $organizationId, string $name, string $email, array $data): int
    {
        $this->db->query('SELECT id FROM commercial_contacts WHERE id_owner=:owner AND site_key=:site AND email=:email LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':email', $email);
        $row = $this->db->fetchOne();
        if ($row) {
            return (int)$row->id;
        }
        $this->db->query('INSERT INTO commercial_contacts(id_owner,site_key,organization_id,name,role_title,email,phone) VALUES(:owner,:site,:organization,:name,:role,:email,:phone)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':organization', $organizationId);
        $this->db->bind(':name', mb_substr($name, 0, 191));
        $this->db->bind(':role', $this->short($data['contact_role'] ?? null, 150));
        $this->db->bind(':email', mb_substr($email, 0, 191));
        $this->db->bind(':phone', $this->short($data['phone'] ?? null, 80));
        $this->db->execute();
        return (int)$this->db->lastId();
    }

    private function recordConsent(int $ownerId, string $siteKey, int $contactId, int $opportunityId, array $data): void
    {
        $this->db->query('INSERT INTO commercial_consents(id_owner,site_key,contact_id,opportunity_id,consent_type,consented,consent_text,source_ip_hash,user_agent) VALUES(:owner,:site,:contact,:opportunity,\'COMMERCIAL_FOLLOW_UP\',1,:text,:ip,:ua)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':contact', $contactId);
        $this->db->bind(':opportunity', $opportunityId);
        $this->db->bind(':text', $this->short($data['consent_text'] ?? 'I agree that VNV Events may contact me about this request.', 1000));
        $ip = (string)($data['_remote_ip'] ?? '');
        $this->db->bind(':ip', $ip !== '' ? hash('sha256', $ip . '|' . ($data['_ip_salt'] ?? 'vnv')) : null);
        $this->db->bind(':ua', $this->short($data['_user_agent'] ?? null, 500));
        $this->db->execute();
    }

    private function recordActivity(int $ownerId, string $siteKey, int $opportunityId, string $type, string $summary, array $details = [], ?int $actorUserId = null): void
    {
        $this->db->query('INSERT INTO commercial_activities(id_owner,site_key,opportunity_id,activity_type,summary,details_json,actor_user_id) VALUES(:owner,:site,:opportunity,:type,:summary,:details,:actor)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':opportunity', $opportunityId);
        $this->db->bind(':type', $type);
        $this->db->bind(':summary', mb_substr($summary, 0, 500));
        $this->db->bind(':details', $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
        $this->db->bind(':actor', $actorUserId);
        $this->db->execute();
    }

    private function assertOpportunity(int $ownerId, string $siteKey, int $opportunityId, ?string $requiredPipeline = null): object
    {
        $this->db->query('SELECT id,pipeline,stage,assigned_user_id,assigned_owner_name FROM commercial_opportunities WHERE id_owner=:owner AND site_key=:site AND id=:id LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':id', $opportunityId);
        $opportunity = $this->db->fetchOne();
        if (!$opportunity || ($requiredPipeline !== null && $opportunity->pipeline !== $requiredPipeline)) {
            throw new \InvalidArgumentException('The selected opportunity is not valid for this operation.');
        }
        return $opportunity;
    }

    private function audit(int $ownerId, string $siteKey, string $entityType, int $entityId, string $action, ?array $before, array $after, ?int $actorUserId): void
    {
        $this->db->query('INSERT INTO commercial_audit_logs(id_owner,site_key,entity_type,entity_id,action,before_json,after_json,actor_user_id) VALUES(:owner,:site,:entity_type,:entity_id,:action,:before,:after,:actor)');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':entity_type', $entityType);
        $this->db->bind(':entity_id', $entityId);
        $this->db->bind(':action', $action);
        $this->db->bind(':before', $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->db->bind(':after', json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->db->bind(':actor', $actorUserId);
        $this->db->execute();
    }

    private function short(mixed $value, int $limit): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
