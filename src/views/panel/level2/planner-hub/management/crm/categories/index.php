<?php

use App\Services\LoginService;
use App\Repositories\CrmCategoryRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    $repo = new CrmCategoryRepository();

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        $institutionOwnerId = null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
        }
        
        if ($institutionOwnerId) {
            $categories = $repo->getAllByInstitutionOwner($institutionOwnerId);
        } else {
            $categories = [];
        }
    } else {
        $categories = $repo->getAllBy([
            ...LoginService::getUserIdAsArray(),
            ...LoginService::getOwnerAsArray()
        ]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "categories" => $categories
    ]);
});

$router->post(function () {
    $context = UserContext::get();

    // ⬅️ Agrega esto:
    if ($context["level"] === 4) {
        MessageUtil::setMessage("Only administrators can delete categories.");
        LocationUtils::reload();
    }

    $user = LoginService::getSession();
    $repo = new CrmCategoryRepository();
    $id = $_POST["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Invalid category ID.");
        LocationUtils::reload();
    }

    $category = $repo->getOne([
        "id" => $id,
        "id_user" => $user->getId()
    ]);

    if (!$category) {
        MessageUtil::setMessage("Category not found.");
        LocationUtils::reload();
    }

    $repo->delete([
        "id" => $id
    ]);

    MessageUtil::setMessage("Category deleted successfully.");
    LocationUtils::reload();
});

$router->run();
