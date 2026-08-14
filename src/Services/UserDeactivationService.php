<?php

namespace App\Services;

use App\Repositories\Connection;
use App\Repositories\UserDeactivationsRepository;
use App\Repositories\UserInstitutionsRepository;

class UserDeactivationService
{
    private UserDeactivationsRepository $deactivationsRepo;
    private UserInstitutionsRepository $userInstitutionsRepo;

    public function __construct()
    {
        $this->deactivationsRepo = new UserDeactivationsRepository();
        $this->userInstitutionsRepo = new UserInstitutionsRepository();
    }

    public function deactivateUserFromInstitution(int $userId, int $institutionId, ?int $deactivatedBy = null, ?string $reason = null, ?int $replacementManagerId = null): array
    {
        $db = new Connection();
        try {
            $db->query("SELECT id_owner FROM institution_profile WHERE id=:id LIMIT 1");
            $db->bind(':id', $institutionId);
            $institution = $db->fetchOne();
            $ownerId = (int)($institution->id_owner ?? 0);
            if ($ownerId <= 0) {
                return ['success'=>false,'message'=>'Institution owner could not be resolved.'];
            }

            $db->query("SELECT id FROM orders WHERE id_owner=:owner AND main_manager_id=:manager AND event_date>=CURDATE() AND is_archived=0 ORDER BY event_date,start_time");
            $db->bind(':owner',$ownerId);$db->bind(':manager',$userId);
            $futureOrders = $db->fetchAll();

            if ($futureOrders && !$replacementManagerId) {
                return ['success'=>false,'message'=>'This manager has '.count($futureOrders).' future event(s). Select a replacement manager before deactivation.'];
            }

            if ($replacementManagerId) {
                $adminEmail = $_ENV['VNV_DEFAULT_MANAGER_EMAIL'] ?? 'info@vnvevents.com';
                $db->query("SELECT u.id,u.level,u.email FROM users u LEFT JOIN event_manager_profiles p ON p.id_owner=:owner AND p.manager_id=u.id WHERE u.id=:replacement AND u.is_active=1 AND ((u.id_owner=:owner AND u.level=4 AND p.is_event_manager=1) OR (u.level=1 AND (u.id=:owner OR LOWER(u.email)=LOWER(:email)))) LIMIT 1");
                $db->bind(':owner',$ownerId);$db->bind(':replacement',$replacementManagerId);$db->bind(':email',$adminEmail);
                if (!$db->fetchOne()) {
                    return ['success'=>false,'message'=>'The selected replacement is not an active Event Manager or the authorized Level 1 account.'];
                }
            }

            $db->beginTransaction();
            if ($futureOrders && $replacementManagerId) {
                $engine = new ManagerAvailabilityService($db);
                foreach ($futureOrders as $futureOrder) {
                    $db->query("SELECT * FROM orders WHERE id=:id AND id_owner=:owner");
                    $db->bind(':id',(int)$futureOrder->id);$db->bind(':owner',$ownerId);
                    $order = $db->fetchOne();
                    $result = $engine->evaluateOrder($order, $replacementManagerId);
                    $engine->record($ownerId,'BULK_REASSIGNMENT',(int)$order->id,$result,$replacementManagerId,$deactivatedBy);
                    $db->query("UPDATE orders SET main_manager_id=:replacement,manager_assignment_status='ASSIGNED',availability_status=:status,availability_checked_at=NOW() WHERE id=:id AND id_owner=:owner");
                    $db->bind(':replacement',$replacementManagerId);$db->bind(':status',$result['status']);$db->bind(':id',(int)$order->id);$db->bind(':owner',$ownerId);$db->execute();
                    $db->query("INSERT INTO manager_assignment_history(id_owner,order_id,previous_manager_id,new_manager_id,action,note,changed_by) VALUES(:owner,:order,:previous,:replacement,'BULK_REASSIGNED_ON_DEACTIVATION',:note,:changed_by)");
                    foreach(['owner'=>$ownerId,'order'=>(int)$order->id,'previous'=>$userId,'replacement'=>$replacementManagerId,'note'=>$reason?:'Manager deactivation reassignment','changed_by'=>$deactivatedBy??0] as $key=>$value)$db->bind(':'.$key,$value);
                    $db->execute();
                }
            }

            $db->query("UPDATE user_institutions SET is_active=0,updated_at=NOW() WHERE user_id=:manager AND (institution_id=:institution OR secondary_institution_id=:institution)");
            $db->bind(':manager',$userId);$db->bind(':institution',$institutionId);$db->execute();
            if ($db->rowCount() < 1) throw new \RuntimeException('The institution relationship could not be deactivated.');
            $db->query("UPDATE event_manager_profiles SET is_event_manager=0,updated_by=:user WHERE id_owner=:owner AND manager_id=:manager");
            $db->bind(':user',$deactivatedBy);$db->bind(':owner',$ownerId);$db->bind(':manager',$userId);$db->execute();
            $db->query("INSERT INTO user_deactivations(user_id,institution_id,deactivated_by,reason,created_at) VALUES(:manager,:institution,:user,:reason,NOW())");
            $db->bind(':manager',$userId);$db->bind(':institution',$institutionId);$db->bind(':user',$deactivatedBy??0);$db->bind(':reason',$reason);$db->execute();
            $db->commit();

            return ['success'=>true,'message'=>'Team member deactivated. '.count($futureOrders).' future event(s) reassigned.','reassigned'=>count($futureOrders)];
        } catch (\Throwable $e) {
            try {$db->rollback();} catch (\Throwable $ignored) {}
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    public function reactivateUserInInstitution(int $userId, int $institutionId): bool
    {
        try { return $this->userInstitutionsRepo->reactivateUserInInstitution($userId, $institutionId); }
        catch (\Exception $e) { return false; }
    }

    public function getUserDeactivationHistory(int $userId): array
    {
        return $this->deactivationsRepo->getDeactivationsForUser($userId);
    }

    public function getDeactivationHistoryByDeactivator(int $deactivatorId): array
    {
        return $this->deactivationsRepo->getDeactivationsByDeactivator($deactivatorId);
    }
}
