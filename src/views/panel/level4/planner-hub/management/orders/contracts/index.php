<?php

use App\Repositories\OrdersContractRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $repo = new OrdersContractRepository();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            
            if ($institution && $institution->id_owner) {
                $contracts = $repo->getAllByInstitutionOwner($institution->id_owner);
            } else {
                $contracts = [];
            }
        } else {
            $contracts = [];
        }
    } else {
        $contracts = $repo->getAllBy(LoginService::getOwnerAsArray());
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "contracts" => $contracts
    ]);
});


$router->post(function () {
    $user = LoginService::getSession();
    $repo = new OrdersContractRepository();
    
    $id = $_POST["id"] ?? null;
    if (!$id) {
        MessageUtil::setMessage("Invalid contract ID.");
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
        $contract = $repo->getOne(["id" => $id]);
    }
    
    if (!$contract) {
        MessageUtil::setMessage("Contract not found or you don't have permission to delete it.");
        LocationUtils::reload();
    }
    
    $repo->delete(["id" => $id]);

    MessageUtil::setMessage("Contract deleted successfully.");
    LocationUtils::reload();
});

$router->run();
