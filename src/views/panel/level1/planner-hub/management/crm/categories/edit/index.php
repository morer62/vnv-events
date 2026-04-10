<?php

use App\Repositories\CrmCategoryRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();
$repo = new CrmCategoryRepository();

$router->get(function () use ($repo) {
    $context = UserContext::get();
    $user = LoginService::getSession();

    $id = $_GET["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("ID not provided.");
        LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            
            if ($institutionOwnerId) {
                $category = $repo->getOneByIdAndOwner($id, $institutionOwnerId);
            } else {
                $category = null;
            }
        } else {
            $category = null;
        }
    } else {
        $category = $repo->getOne([
            "id" => $id,
            ...LoginService::getUserIdAsArray(),
            ...LoginService::getOwnerAsArray()
        ]);
    }

    if (!$category) {
        MessageUtil::setMessage("Category not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "category" => $category
    ]);
});

$router->post(function () use ($repo) {
    $context = UserContext::get();
    $user = LoginService::getSession();

    $id = $_GET["id"] ?? null;
    $name = trim($_POST["name"] ?? "");

    if (!$id || $name === "") {
        MessageUtil::setMessage("All fields are required.");
        LocationUtils::reload();
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            
            if ($institutionOwnerId) {
                $category = $repo->getOneByIdAndOwner($id, $institutionOwnerId);
                
                if (!$category) {
                    MessageUtil::setMessage("Category not found or you don't have permission.");
                    LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
                }
            } else {
                MessageUtil::setMessage("Institution not found.");
                LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
            }
        } else {
            MessageUtil::setMessage("No institution selected.");
            LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
        }
    }

    $repo->update([
        "name" => $name
    ], [
        "id" => $id
    ]);

    MessageUtil::setMessage("Category updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/management/crm/categories");
});

$router->run();
