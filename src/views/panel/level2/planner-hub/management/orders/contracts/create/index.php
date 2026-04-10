<?php

use App\Services\LoginService;
use App\Repositories\OrdersContractRepository;
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

    $repo = new OrdersContractRepository();

    $title = trim($_POST["title"] ?? "");
    $content = trim($_POST["content"] ?? "");

    if ($title === "" || $content === "") {
        MessageUtil::setMessage("Both title and content are required.");
        LocationUtils::reload();
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $repo->addWithExplicitOwner([
                    "title" => $title,
                    "content" => $content,
                    "id_owner" => $institution->id_owner
                ]);
            } else {
                MessageUtil::setMessage("Institution not found.");
                LocationUtils::reload();
            }
        } else {
            MessageUtil::setMessage("No institution selected.");
            LocationUtils::reload();
        }
    } else {
        $repo->add([
            "title" => $title,
            "content" => $content,
            ...LoginService::getOwnerAsArray()
        ]);
    }

    MessageUtil::setMessage("Contract created successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/contracts");
});

$router->run();
