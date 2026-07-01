<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Services\LoginService;
use App\Repositories\UserInstitutionsRepository;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if (!$currentInstitutionId) {
            $userInstitutionsRepo = new UserInstitutionsRepository();
            $activeInstitutions = $userInstitutionsRepo->getAllUserInstitutions($user->getId());
            
            if (!empty($activeInstitutions)) {
                $currentInstitutionId = $activeInstitutions[0]->institution_id;
                $_SESSION['current_institution_id'] = $currentInstitutionId;
            }
        }
        
        if ($currentInstitutionId) {
            LoginService::reloadUserPermissions((int)$currentInstitutionId);
            $user = LoginService::getSession();
        }

        if (empty($user->getPermissions2())) {
            LocationUtils::redirectInternal("panel/planner-hub/team");
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
