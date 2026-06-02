<?php

use App\Services\LoginService;
use App\Repositories\PayrollTimeLogsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Services\TeamMemberContractService;

$router = new Router();
$repo = new PayrollTimeLogsRepository();
$user = LoginService::getSession();

$router->get(function () use ($repo, $user): string {
    $currentOwnerId = $user->getOwner();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            $currentOwnerId = $institutionOwnerId ?? $currentOwnerId;
        }
    }
    
    $logs = $repo->getAllBy([
        "id_user" => $user->getId(),
        "end_time" => null,
        "id_owner" => $currentOwnerId
    ]);

    $activeLog = count($logs) > 0 ? $logs[0] : null;

    if ($activeLog) {
        $activeLog->start_time = strtotime($activeLog->start_time);
    }
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "activeLog" => $activeLog
    ]);
});

$router->post(callback: function () use ($repo, $user): void {
    $action = $_POST["action"] ?? "";
    
    $currentOwnerId = $user->getOwner();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            $currentOwnerId = $institutionOwnerId ?? $currentOwnerId;
        }
    }
    
    $logs = $repo->getAllBy([
        "id_user" => $user->getId(),
        "id_owner" => $currentOwnerId,
        "end_time" => null
    ]);

    if ($action === "start") {
        if (count($logs) > 0) {
            MessageUtil::setMessage("You already started a session.");
            LocationUtils::reload();
        }

        if ($user->getLevel() === 4 && !(new TeamMemberContractService())->isClockInAllowed($user->getId(), (int)$currentOwnerId)) {
            MessageUtil::setMessage("Tu contrato todavia no ha sido validado. Abre Mi contrato para firmarlo o contacta al administrador antes de iniciar tu reloj.");
            LocationUtils::reload();
        }

        $repo->add([
            "id_user" => $user->getId(),
            "start_time" => date("Y-m-d H:i:s"),
            "id_owner" => $currentOwnerId,
            "end_time" => null,
            "is_paid" => 0
        ]);

        MessageUtil::setMessage("Work session started.");
        LocationUtils::reload();
    }

    if ($action === "end") {

        $activeLog = count($logs) > 0 ? $logs[0] : null;

        if (!$activeLog) {
            MessageUtil::setMessage("No active session found.");
            LocationUtils::reload();
        }

        $repo->update([ "end_time" => date("Y-m-d H:i:s")], [
            "id" => $activeLog->id
        ]);

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
