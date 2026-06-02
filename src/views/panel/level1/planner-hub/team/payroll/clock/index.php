<?php

use App\Services\LoginService;
use App\Repositories\PayrollTimeLogsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Services\NotificationService;
use App\Services\TeamMemberContractService;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserInstitutionsRepository;

date_default_timezone_set('UTC');

$router = new Router();
$repo = new PayrollTimeLogsRepository();
$user = LoginService::getSession();

$router->get(function () use ($repo, $user): string {
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    
    $isLevel4 = $user->getLevel() == 4;
    
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitutionRole = $_SESSION['current_institution_role'] ?? null;
    
    $userInstitutions = [];
    $hasMultipleInstitutions = false;
    
    // Validar que usuarios de nivel 4 tengan al menos una institución asociada
    if ($isLevel4) {
        $userInstitutions = $userInstitutionService->getUserAvailableInstitutions($user->getId());
        
        if (empty($userInstitutions)) {
            MessageUtil::setMessage("⚠️ You need to be associated with a company to use the clock. Please contact your administrator to be assigned to a company.");
            LocationUtils::redirectInternal("panel/home");
            exit;
        }
        
        $hasMultipleInstitutions = count($userInstitutions) > 1;
    }
    
    $currentInstitution = null;
    $userInstitutionsRepo = new UserInstitutionsRepository();
    $userInstitutionData = null;
    $roleName = null;
    $hourlyRate = null;
    
    try {
        if ($isLevel4 && $currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            
            if (!$currentInstitution) {
                $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
                if ($primaryInstitution) {
                    $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                    $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                    $_SESSION['current_institution_role'] = 'owner';
                }
            }
        } elseif ($isLevel4) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
            if ($primaryInstitution) {
                $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                $_SESSION['current_institution_role'] = 'owner';
            }
        }
        
        if ($currentInstitution) {
            $userInstitutionData = $userInstitutionsRepo->getUserInstitutionRecord($user->getId(), $currentInstitution->id);
            if ($userInstitutionData) {
                $roleName = $userInstitutionData->role_name ?? 'No Role Assigned';
                $hourlyRate = $userInstitutionData->hourly_rate ?? 0;
            }
        }
    } catch (Exception $e) {
    }

    // Para nivel 4, debe tener una institución válida
    if ($isLevel4 && !$currentInstitution) {
        MessageUtil::setMessage("⚠️ You need to be associated with a company to use the clock. Please contact your administrator to be assigned to a company.");
        LocationUtils::redirectInternal("panel/home");
        exit;
    }

    $currentInstitutionOwner = $currentInstitution ? $currentInstitution->id_owner : $user->getOwner();

    // Validar que currentInstitutionOwner no sea null para nivel 4
    if ($isLevel4 && !$currentInstitutionOwner) {
        MessageUtil::setMessage("⚠️ Unable to determine company owner. Please contact your administrator.");
        LocationUtils::redirectInternal("panel/home");
        exit;
    }

    $logs = $repo->getActiveLogsByUserAndOwner($user->getId(), $currentInstitutionOwner);

    $activeLog = count($logs) > 0 ? $logs[0] : null;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "activeLog" => $activeLog,
        'userInstitutions' => $userInstitutions,
        'currentInstitution' => $currentInstitution,
        'currentInstitutionRole' => $currentInstitutionRole,
        'hasMultipleInstitutions' => $hasMultipleInstitutions,
        'currentInstitutionOwner' => $currentInstitutionOwner,
        'isLevel4' => $isLevel4,
        'userInstitutionData' => $userInstitutionData,
        'roleName' => $roleName,
        'hourlyRate' => $hourlyRate
    ]);
});

$router->post(callback: function () use ($repo, $user): void {
    if (isset($_POST['switch_institution'])) {
        if ($user->getLevel() != 4) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $institutionId = $_POST['institution_id'] ?? null;
        $role = $_POST['role'] ?? 'employee';
        
        if ($institutionId) {
            $_SESSION['current_institution_id'] = $institutionId;
            $_SESSION['current_institution_role'] = $role;
            
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No institution ID provided']);
            exit;
        }
    }
    
    $action = $_POST["action"] ?? "";
    $tz = $_POST["tz"] ?? "America/New_York";

    $repo->db->setTimezone($tz);

    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    
    // Para nivel 4, obtener la institución correcta
    $currentInstitutionOwner = null;
    $isLevel4 = $user->getLevel() == 4;
    
    if ($isLevel4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            if ($currentInstitution) {
                $currentInstitutionOwner = $currentInstitution->id_owner;
            }
        }
        
        // Si no hay institución en sesión, obtener la primaria
        if (!$currentInstitutionOwner) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
            if ($primaryInstitution) {
                $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                if ($currentInstitution) {
                    $currentInstitutionOwner = $currentInstitution->id_owner;
                    $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                }
            }
        }
        
        // Validar que tenga institución antes de continuar
        if (!$currentInstitutionOwner) {
            MessageUtil::setMessage("⚠️ You need to be associated with a company to use the clock. Please contact your administrator to be assigned to a company.");
            LocationUtils::redirectInternal("panel/home");
            exit;
        }
    } else {
        $currentInstitutionOwner = $userInstitutionService->getCurrentInstitutionOwner() ?? $user->getOwner();
    }

    $logs = $repo->getActiveLogsByUserAndOwner($user->getId(), $currentInstitutionOwner);

    if ($action === "start") {
        if (count($logs) > 0) {
            MessageUtil::setMessage("You already started a session.");
            LocationUtils::reload();
        }

        if ($isLevel4 && !(new TeamMemberContractService())->isClockInAllowed($user->getId(), (int)$currentInstitutionOwner)) {
            MessageUtil::setMessage("Tu contrato todavia no ha sido validado. Abre Mi contrato para firmarlo o contacta al administrador antes de iniciar tu reloj.");
            LocationUtils::reload();
        }

        $repo->startNow(
            $user->getId(),
            $currentInstitutionOwner,
            $_POST["location_lat"]  ?? null,
            $_POST["location_long"] ?? null
        );

        $memberName = $user->getName() . ' ' . $user->getLastname();
        NotificationService::sendToUsers(
            [$currentInstitutionOwner],
            '⏱️ Clock In',
            $memberName . ' started a work session.'
        );

        MessageUtil::setMessage("Work session started.");
        LocationUtils::reload();
    }

    if ($action === "end") {
        $activeLog = count($logs) > 0 ? $logs[0] : null;
        
        if (!$activeLog) {
            MessageUtil::setMessage("No active session found.");
            LocationUtils::reload();
        }

        $repo->stopNow(
            $activeLog->id,
            $_POST["location_lat"]  ?? null,
            $_POST["location_long"] ?? null,
            $_POST["notes"]         ?? null
        );

        $memberName = $user->getName() . ' ' . $user->getLastname();
        NotificationService::sendToUsers(
            [$currentInstitutionOwner],
            '⏹️ Clock Out',
            $memberName . ' ended a work session.'
        );

        MessageUtil::setMessage("Work session ended.");
        LocationUtils::reload();
    }

    MessageUtil::setMessage("Invalid action.");
    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
}
