<?php

use App\Services\LoginService;
use App\Repositories\OrdersContractRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $repo = new OrdersContractRepository();

    $id = $_GET["id"] ?? null;
    if (!$id) LocationUtils::redirectInternal("panel/planner-hub/management/orders/contracts");

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
        MessageUtil::setMessage("Contract not found or you don't have permission to edit it.");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/contracts");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "contract" => $contract
    ]);
});

$router->post(function () {
    $repo = new OrdersContractRepository();

    $id = $_POST["id"] ?? null;
    $title = trim($_POST["title"] ?? "");
    $content = trim($_POST["content"] ?? "");

    if (!$id || $title === "" || $content === "") {
        MessageUtil::setMessage("All fields are required.");
        LocationUtils::reload();
    }

    $repo->update([
        "title" => $title,
        "content" => $content
    ], ["id" => $id]);

    MessageUtil::setMessage("Contract updated successfully!");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/contracts");
});

$router->run();
