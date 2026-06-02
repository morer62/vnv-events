<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\StoreUserRolesRepository;
use App\Utils\LocationUtils;
use App\Utils\AvomealContext;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    $storeUserRolesRepo = new StoreUserRolesRepository();
    $avomealOwnerId = AvomealContext::ownerId();

    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitution = null;
    $storeTeamRole = 'general';

    if ($currentInstitutionId) {
        $currentInstitution = $institutionRepo->getById($currentInstitutionId);
        if ($currentInstitution && (int)($currentInstitution->id_owner ?? 0) !== $avomealOwnerId) {
            $currentInstitution = null;
        }
    }

    if (!$currentInstitution) {
        $candidateInstitution = $institutionRepo->getByOwner($avomealOwnerId);
        if ($candidateInstitution) {
            $currentInstitution = $candidateInstitution;
            $_SESSION['current_institution_id'] = $candidateInstitution->id;
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
