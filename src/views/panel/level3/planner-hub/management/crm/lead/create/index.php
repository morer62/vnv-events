<?php

use App\Repositories\CrmCategoryRepository;
use App\Repositories\CrmLeadRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $categoryRepo = new CrmCategoryRepository();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            
            if ($institutionOwnerId) {
                $categories = $categoryRepo->getAllByInstitutionOwner($institutionOwnerId);
            } else {
                $categories = [];
            }
        } else {
            $categories = [];
        }
    } else {
        $categories = $categoryRepo->getAllBy([
            ...LoginService::getUserIdAsArray(),
            ...LoginService::getOwnerAsArray()
        ]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categories
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $repo = new CrmLeadRepository();

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $address = $_POST["address"] ?? "";
    $categoryId = $_POST["category_id"] ?? null;

    // Nuevos campos
    $languaje = strtolower(trim($_POST["languaje"] ?? "english"));
    $comments = trim($_POST["comments"] ?? "");

    // Normalización/seguridad simple
    $allowedLangs = ["english","spanish","portuguese","french","other"];
    if (!in_array($languaje, $allowedLangs, true)) {
        $languaje = "english";
    }
    if (mb_strlen($comments) > 240) {
        $comments = mb_substr($comments, 0, 240);
    }

    if (empty($name) || empty($email) || empty($phone) || !$categoryId) {
        MessageUtil::setMessage("All fields are required.");
        LocationUtils::reload();
    }

    // Verificar si ya existe un lead con el mismo email para ese usuario
    $existing = $repo->getOne([
        "id_user" => $user->getId(),
        "email" => $email
    ]);

    if ($existing) {
        MessageUtil::setMessage("A lead with this email already exists.");
        LocationUtils::reload();
    }

    $dataToInsert = [
        "name"        => $name,
        "email"       => $email,
        "phone"       => $phone,
        "address"     => $address,
        "languaje"    => $languaje,
        "comments"    => $comments,
        "id_category" => $categoryId,
        "id_status"   => 1,
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

    if ($user->getLevel() === 4 && isset($dataToInsert['id_owner'])) {
        $repo->addWithExplicitOwner($dataToInsert);
    } else {
        $repo->add($dataToInsert);
    }

    MessageUtil::setMessage("Lead created successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
});

$router->run();
