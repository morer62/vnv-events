<?php

use App\Services\LoginService;
use App\Repositories\CrmCategoryRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();

     

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context
    ]);
});

$router->post(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();

    $name = trim($_POST["name"]);

    if ($name === "") {
        MessageUtil::setMessage("The name is required.");
        LocationUtils::reload();
    }

    $dataToInsert = [
        "name" => $name,
        ...LoginService::getUserIdAsArray(true)
    ];

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            
            if ($institution && $institution->id_owner) {
                $dataToInsert["id_owner"] = $institution->id_owner;
            } else {
                $dataToInsert = array_merge($dataToInsert, LoginService::getOwnerAsArray());
            }
        } else {
            $dataToInsert = array_merge($dataToInsert, LoginService::getOwnerAsArray());
        }
    } else {
        $dataToInsert = array_merge($dataToInsert, LoginService::getOwnerAsArray());
    }

    $repo = new CrmCategoryRepository();
    
    if ($user->getLevel() === 4 && isset($dataToInsert['id_owner'])) {
        $repo->addWithExplicitOwner($dataToInsert);
    } else {
        $repo->add($dataToInsert);
    }

    LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
});

$router->run();
