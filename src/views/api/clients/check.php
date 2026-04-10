<?php

use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $email = $_GET["email"] ?? null;
    $session = LoginService::getSession();

    if (!$email || !$session) {
        return JsonResponse::createResponse(["exists" => false]);
    }

    $checkOwnerId = $session->getIdOwner();
    
    if ($session->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $checkOwnerId = $institution->id_owner;
            }
        }
    }

    $repo = new UserRepository();
    $client = $repo->getOne([
        "email" => $email,
        "level" => 5
    ]);

    if (!$client) {
        return JsonResponse::createResponse(["exists" => false]);
    }

    $assocRepo = new ClientsUsersRepository();
    $isLinked = $assocRepo->exists($client->id, $checkOwnerId);

    return JsonResponse::createResponse([
        "exists" => true,
        "is_linked" => $isLinked,
        "id" => $client->id,
        "name" => $client->name . " " . $client->lastname,
        "email" => $client->email,
        "phone" => $client->phone
    ]);
});



$router->run();
