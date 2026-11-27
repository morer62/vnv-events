<?php

namespace App\Services;

use DomainException;
use PDO;

final class ProductionsCenterAccess
{
    public const GRANTS = [
        'pmc_center' => 'Open the management center and view assigned workspaces',
        'pmc_provision' => 'Create organizations and demos',
        'pmc_invite' => 'Invite workspace administrators',
        'pmc_reset' => 'Reset a demo and revoke its previous memberships',
        'pmc_lifecycle' => 'Activate, suspend, reactivate or deactivate an assigned account',
    ];

    public function __construct(private PDO $db, private int $owner, private string $site) {}

    public function actor(int $id, int $sessionLevel, int $verifiedId, int $institution = 0): array
    {
        if ($this->site !== 'vnvevents' || $this->owner < 1 || $id < 1 || $verifiedId !== $id) {
            throw new DomainException('Please sign in with your VNV Events account.');
        }
        $q = $this->db->prepare('SELECT level, COALESCE(id_owner,id) AS owner FROM users WHERE id=? AND is_active=1');
        $q->execute([$id]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if (!$u || (int)$u['owner'] !== $this->owner || (int)$u['level'] !== $sessionLevel ||
            !in_array($sessionLevel, [1,4], true) || ($sessionLevel === 1 && $id !== $this->owner)) {
            throw new DomainException('This account does not have access to Productions.');
        }
        if ($institution > 0) {
            $q = $this->db->prepare('SELECT 1 FROM institution_profile WHERE id=? AND id_owner=?');
            $q->execute([$institution, $this->owner]);
            if (!$q->fetchColumn()) {
                throw new DomainException('Switch to the VNV Events organization to open Productions.');
            }
        }
        $q = $this->db->prepare('SELECT feature_slug FROM user_feature_permissions WHERE id_user=?');
        $q->execute([$id]);
        $grants = $q->fetchAll(PDO::FETCH_COLUMN);
        $direction = $sessionLevel === 1;
        if (!$direction && !in_array('pmc_center', $grants, true)) {
            throw new DomainException('Productions access has not been assigned to this account.');
        }
        $caps = ['center.view','accounts.view','accounts.request','onboarding.view'];
        foreach (['pmc_provision'=>'accounts.create','pmc_invite'=>'accounts.invite','pmc_reset'=>'accounts.reset',
                  'pmc_lifecycle'=>'accounts.lifecycle'] as $grant=>$cap) {
            if ($direction || in_array($grant, $grants, true)) {
                $caps[] = $cap;
            }
        }
        $crm = $direction || (bool)array_intersect(['crm_sponsors','crm'], $grants);
        $q = $this->db->prepare("SELECT u.id, TRIM(CONCAT_WS(' ',TRIM(u.name),u.lastname)) FROM users u
            WHERE u.is_active=1 AND ((u.id=? AND u.level=1) OR (u.id_owner=? AND u.level=4 AND EXISTS
            (SELECT 1 FROM user_feature_permissions p WHERE p.id_user=u.id AND p.feature_slug='pmc_center')))
            AND (?=1 OR u.id=?) ORDER BY u.name,u.lastname,u.id");
        $q->execute([$this->owner,$this->owner,(int)$direction,$id]);
        return ['owner_id'=>$this->owner,'site_key'=>$this->site,'user_id'=>$id,'level'=>$sessionLevel,
            'capabilities'=>$caps,'crm'=>$crm,'managers'=>$q->fetchAll(PDO::FETCH_KEY_PAIR)];
    }

    public function staff(): array
    {
        $q = $this->db->prepare("SELECT u.id,u.name,u.lastname,p.feature_slug FROM users u LEFT JOIN
            user_feature_permissions p ON p.id_user=u.id AND LEFT(p.feature_slug,4)='pmc_'
            WHERE u.id_owner=? AND u.level=4 AND u.is_active=1 ORDER BY u.name,u.lastname,u.id");
        $q->execute([$this->owner]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $rows[$id] ??= ['id'=>$id,'name'=>trim(trim($r['name']).' '.$r['lastname']),'grants'=>[]];
            if ($r['feature_slug']) {
                $rows[$id]['grants'][] = $r['feature_slug'];
            }
        }
        return $rows;
    }

    public function setGrants(array $actor, int $staffId, array $values): void
    {
        if ($actor['level'] !== 1 || $actor['owner_id'] !== $this->owner || $actor['user_id'] !== $this->owner) {
            throw new DomainException('Only VNV direction can change delegation.');
        }
        $grants = array_values(array_intersect(array_keys(self::GRANTS), $values));
        if (!in_array('pmc_center', $grants, true)) {
            $grants = [];
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id FROM users WHERE id=? AND level=4 AND is_active=1 AND id_owner=? FOR UPDATE');
            $q->execute([$staffId,$this->owner]);
            if (!$q->fetchColumn()) {
                throw new DomainException('Select an active team member of VNV Events.');
            }
            $q = $this->db->prepare("DELETE FROM user_feature_permissions WHERE id_user=? AND LEFT(feature_slug,4)='pmc_'");
            $q->execute([$staffId]);
            $q = $this->db->prepare('INSERT INTO user_feature_permissions (id_user,feature_slug) VALUES (?,?)');
            foreach ($grants as $grant) {
                $q->execute([$staffId,$grant]);
            }
            $this->audit($actor,'delegation.save','completed',$staffId,implode(',',$grants));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function audit(array $actor, string $action, string $result, ?int $target = null, string $detail = ''): int
    {
        $q = $this->db->prepare('INSERT INTO productions_center_audit
            (id_owner,site_key,actor_id,action,result,target_id,detail) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$this->owner,$this->site,$actor['user_id'],$action,$result,$target,$detail]);
        return (int)$this->db->lastInsertId();
    }

    public function finishAudit(int $id, string $outcome): void
    {
        $q = $this->db->prepare('UPDATE productions_center_audit SET result=? WHERE id=? AND id_owner=? AND site_key=?');
        $q->execute([$outcome,$id,$this->owner,$this->site]);
    }
}
