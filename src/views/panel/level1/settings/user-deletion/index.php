<?php

use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Repositories\UserDeletionRepository;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $repo = new UserDeletionRepository();

    $request = $repo->getByUser($user->getId());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "request" => $request
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $repo = new UserDeletionRepository();

    // Solicitar eliminación
    if (
        isset($_POST["confirm_delete"]) &&
        $_POST["confirm_delete"] === "yes" &&
        in_array($_POST["deletion_status"] ?? '', ["DONE_FULL", "DONE_PARTIAL"])
    ) {
        $repo->requestDeletion($user->getId(), $_POST["deletion_status"]);
        LocationUtils::reload();
        return;
    }

    // Cancelar solicitud
    if (isset($_POST["cancel_deletion"]) && $_POST["cancel_deletion"] === "yes") {
        $repo->deleteExisting($user->getId());
        LocationUtils::reload();
        return;
    }

    // Si nada coincide, redireccionar por seguridad
    LocationUtils::redirectInternal("panel/settings");
});

$router->run();
