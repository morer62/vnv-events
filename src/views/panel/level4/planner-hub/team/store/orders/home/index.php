<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    $storeUserRolesRepo = new StoreUserRolesRepository();

    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitution = null;
    $storeTeamRole = 'general';

    if ($currentInstitutionId) {
        $currentInstitution = $institutionRepo->getById($currentInstitutionId);
    }

    if (!$currentInstitution) {
        $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
        if ($primaryInstitution) {
            $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
            $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
        }
    }

    if ($currentInstitution) {
        $ownerId = (int)($currentInstitution->id_owner ?? 0);
        if ($ownerId > 0) {
            $storeTeamRole = $storeUserRolesRepo->getRoleValueByOwnerAndUser($ownerId, (int)$user->getId()) ?: 'general';
        }
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'user' => $user,
        'currentInstitution' => $currentInstitution,
        'storeTeamRole' => $storeTeamRole
    ]);
});

$router->post(function () {
    LocationUtils::redirectInternal('panel/planner-hub/team/store/orders/home');
});

$router->run();