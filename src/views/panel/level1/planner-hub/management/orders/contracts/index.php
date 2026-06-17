<?php

use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersAcceptanceContractTemplateRepository;
use App\Services\LoginService;
use App\Services\TranslationService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $repo = new OrdersContractRepository();
    $acceptanceTemplateRepo = new OrdersAcceptanceContractTemplateRepository();
    
    $ownerId = null;
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            
            if ($institution && $institution->id_owner) {
                $contracts = $repo->getAllByInstitutionOwner($institution->id_owner);
                $ownerId = $institution->id_owner;
            } else {
                $contracts = [];
            }
        } else {
            $contracts = [];
        }
    } else {
        $ownerId = in_array($user->getLevel(), [1, 2, 3], true) ? (int)$user->getId() : $user->getOwner();
        $contracts = $repo->getAllByInstitutionOwner($ownerId);
    }

    $acceptanceTemplate = null;
    if ($ownerId) {
        $acceptanceTemplate = $acceptanceTemplateRepo->getOrCreateByOwner($ownerId);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "contracts" => $contracts,
        "acceptanceTemplate" => $acceptanceTemplate
    ]);
});


$router->post(function () {
    TranslationService::detectLocale();
    
    $user = LoginService::getSession();
    
    if (isset($_POST["action"]) && $_POST["action"] === "update_acceptance_template") {
        $acceptanceTemplateRepo = new OrdersAcceptanceContractTemplateRepository();
        $content = trim($_POST["content"] ?? "");
        
        if ($content === "") {
            MessageUtil::setMessage(TranslationService::trans('planner_hub.content_cannot_empty'));
            LocationUtils::reload();
        }
        
        $ownerId = null;
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                if ($institution && $institution->id_owner) {
                    $ownerId = $institution->id_owner;
                }
            }
        } else {
            $ownerId = in_array($user->getLevel(), [1, 2, 3], true) ? (int)$user->getId() : $user->getOwner();
        }
        
        if (!$ownerId) {
            MessageUtil::setMessage(TranslationService::trans('planner_hub.unable_determine_owner'));
            LocationUtils::reload();
        }
        
        $acceptanceTemplateRepo->updateByOwner($ownerId, $content);
        MessageUtil::setMessage(TranslationService::trans('planner_hub.acceptance_template_updated'));
        LocationUtils::reload();
    }
    
    $repo = new OrdersContractRepository();
    
    $id = $_POST["id"] ?? null;
    if (!$id) {
        MessageUtil::setMessage(TranslationService::trans('planner_hub.invalid_contract_id'));
        LocationUtils::reload();
    }
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $contract = $repo->getOneByIdAndOwner($id, $institution->id_owner);
            } else {
                $contract = null;
            }
        } else {
            $contract = null;
        }
    } else {
        $ownerId = in_array($user->getLevel(), [1, 2, 3], true) ? (int)$user->getId() : $user->getOwner();
        $contract = $repo->getOneByIdAndOwner((int)$id, $ownerId);
    }
    
    if (!$contract) {
        MessageUtil::setMessage(TranslationService::trans('planner_hub.contract_not_found_delete'));
        LocationUtils::reload();
    }
    
    $repo->delete(["id" => $id]);

    MessageUtil::setMessage(TranslationService::trans('planner_hub.contract_deleted_successfully'));
    LocationUtils::reload();
});

$router->run();
