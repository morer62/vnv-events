<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Repositories\TeamMemberContractsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    $storeUserRolesRepo = new StoreUserRolesRepository();
    
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitutionRole = $_SESSION['current_institution_role'] ?? null;
    
    $userInstitutions = $userInstitutionService->getUserAvailableInstitutions($user->getId());
    
    $currentInstitution = null;
    $userInstitutionsRepo = new UserInstitutionsRepository();
    $userInstitutionData = null;
    $roleName = null;
    $hourlyRate = null;
    $contractDetail = null;
    $currentOwnerId = null;
    $storeTeamRole = 'general';
    
    try {
        if ($currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            
            if (!$currentInstitution) {
                $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
                if ($primaryInstitution) {
                    $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                    $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                    $_SESSION['current_institution_role'] = 'owner';
                }
            }
        } else {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
            if ($primaryInstitution) {
                $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                $_SESSION['current_institution_role'] = 'owner';
            }
        }
        
        if ($currentInstitution) {
            $currentOwnerId = (int)($currentInstitution->id_owner ?? 0);

            $userInstitutionData = $userInstitutionsRepo->getUserInstitutionRecord($user->getId(), $currentInstitution->id);
            if ($userInstitutionData) {
                $roleName = $userInstitutionData->role_name ?? 'No Role Assigned';
                $hourlyRate = $userInstitutionData->hourly_rate ?? 0;
                $contractDetail = $userInstitutionData->contract_detail ?? null;
            }

            if ($currentOwnerId > 0) {
                $storeTeamRole = $storeUserRolesRepo->getRoleValueByOwnerAndUser($currentOwnerId, (int)$user->getId()) ?: 'general';
            }
        }
    } catch (Exception $e) {
    }
    
    $hasMultipleInstitutions = count($userInstitutions) > 1;

    $storeRoleLabel = 'General';
    $storeRoleIcon = 'fa-layer-group';
    $storeRoleDescription = 'Support store operations, assigned jobs, and order coordination.';
    $teamContract = null;
    $isEventManager = false;
    $futureAvailabilityBlocks = 0;

    if ($currentOwnerId > 0) {
        $teamContract = (new TeamMemberContractsRepository())->getLatestForMember((int)$user->getId(), (int)$currentOwnerId);
        try {
            $managerDb = new \App\Repositories\Connection();
            $managerDb->query("SELECT is_event_manager FROM event_manager_profiles WHERE id_owner=:owner AND manager_id=:manager");
            $managerDb->bind(':owner',$currentOwnerId);$managerDb->bind(':manager',(int)$user->getId());
            $managerProfile=$managerDb->fetchOne();
            $isEventManager=$managerProfile && (int)$managerProfile->is_event_manager===1;
            if($isEventManager){
                $managerDb->query("SELECT COUNT(*) total FROM manager_availability WHERE id_owner=:owner AND manager_id=:manager AND availability='UNAVAILABLE' AND ends_at>=NOW()");
                $managerDb->bind(':owner',$currentOwnerId);$managerDb->bind(':manager',(int)$user->getId());
                $futureAvailabilityBlocks=(int)($managerDb->fetchOne()->total??0);
            }
        } catch (\Throwable $e) {
            $isEventManager=false;
        }
    }

    if ($storeTeamRole === 'kitchen') {
        $storeRoleLabel = 'Kitchen';
        $storeRoleIcon = 'fa-kitchen-set';
        $storeRoleDescription = 'Focus on preparation, quantities, assembly, and kitchen handoff.';
    } elseif ($storeTeamRole === 'delivery') {
        $storeRoleLabel = 'Delivery';
        $storeRoleIcon = 'fa-truck-fast';
        $storeRoleDescription = 'Focus on assigned deliveries, route execution, and proof of delivery.';
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'user' => $user,
        'userInstitutions' => $userInstitutions,
        'currentInstitution' => $currentInstitution,
        'currentInstitutionRole' => $currentInstitutionRole,
        'hasMultipleInstitutions' => $hasMultipleInstitutions,
        'userInstitutionData' => $userInstitutionData,
        'roleName' => $roleName,
        'hourlyRate' => $hourlyRate,
        'contractDetail' => $contractDetail,
        'storeTeamRole' => $storeTeamRole,
        'storeRoleLabel' => $storeRoleLabel,
        'storeRoleIcon' => $storeRoleIcon,
        'storeRoleDescription' => $storeRoleDescription,
        'teamContract' => $teamContract
        ,'isEventManager' => $isEventManager
        ,'futureAvailabilityBlocks' => $futureAvailabilityBlocks
    ]);
});

$router->post(function () {
    if (isset($_POST['switch_institution'])) {
        MessageUtil::setMessage('Team members can only switch between Team and Client views.');
        LocationUtils::redirectInternal("panel/home");
        return;
    }
    
    if (isset($_POST['level'])) {
        $user = LoginService::getSession();
        $newLevel = (int)($_POST['level'] ?? 0);

        if (!$user || $newLevel !== 5) {
            LocationUtils::redirectInternal("panel/home");
            return;
        }

        $repo = new UserRepository();
        $repo->update(["level" => $newLevel], ["id" => $user->getId()]);

        $user->setLevel($newLevel);
        LoginService::setSession($user);

        LocationUtils::redirectInternal("panel/");
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
