<?php

use App\Repositories\CrmLeadRepository;
use App\Repositories\CrmCategoryRepository;
use App\Repositories\CrmStatusRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();
$leadRepo = new CrmLeadRepository();
$user = LoginService::getSession();

$router->get(function () use ($leadRepo, $user) {
    $context = UserContext::get();

    $categoryRepo = new CrmCategoryRepository();
    $statusRepo   = new CrmStatusRepository();

    $id = $_GET["id"] ?? null;
    if (!$id) {
        LocationUtils::redirectInternal("panel/planner-hub/management/crm");
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            
            if ($institutionOwnerId) {
                $lead = $leadRepo->getOneByIdAndOwner($id, $institutionOwnerId);
                $categories = $categoryRepo->getAllByInstitutionOwner($institutionOwnerId);
            } else {
                $lead = null;
                $categories = [];
            }
        } else {
            $lead = null;
            $categories = [];
        }
    } else {
        $lead = $leadRepo->getOne([
            "id" => $id,
            ...LoginService::getOwnerAsArray()
        ]);
        $categories = $categoryRepo->getAllBy(["id_user" => $user->getOwner()]);
    }

    if (!$lead) {
        MessageUtil::setMessage("Lead not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "lead"       => $lead,
        "categories" => $categories,
        "statuses"   => $statusRepo->getAll()
    ]);
});

$router->post(function () use ($leadRepo, $user) {
    $context = UserContext::get();

    $id         = $_POST["id"] ?? null;
    $name       = trim($_POST["name"] ?? "");
    $email      = trim($_POST["email"] ?? "");
    $phone      = trim($_POST["phone"] ?? "");
    $address    = $_POST["address"] ?? "";
    $idCategory = $_POST["id_category"] ?? null;

    $languaje = strtolower(trim($_POST["languaje"] ?? "english"));
    $comments = trim($_POST["comments"] ?? "");

    $allowedLangs = ["english","spanish","portuguese","french","other"];
    if (!in_array($languaje, $allowedLangs, true)) {
        $languaje = "english";
    }
    if (mb_strlen($comments) > 240) {
        $comments = mb_substr($comments, 0, 240);
    }

    if (!$id || $name === "") {
        MessageUtil::setMessage("Name is required.");
        LocationUtils::reload();
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            
            if ($institutionOwnerId) {
                $lead = $leadRepo->getOneByIdAndOwner($id, $institutionOwnerId);
                
                if (!$lead) {
                    MessageUtil::setMessage("Lead not found or you don't have permission.");
                    LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
                }
            } else {
                MessageUtil::setMessage("Institution not found.");
                LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
            }
        } else {
            MessageUtil::setMessage("No institution selected.");
            LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
        }
    }

    $leadRepo->update([
        "name"        => $name,
        "email"       => $email,
        "phone"       => $phone,
        "address"     => $address,
        "languaje"    => $languaje,
        "comments"    => $comments,
        "id_category" => $idCategory
    ], [
        "id" => $id
    ]);

    MessageUtil::setMessage("Lead updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
});

$router->run();
