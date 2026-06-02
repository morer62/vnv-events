<?php

use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Services\ApiAuthService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $email = $_GET["email"] ?? null;
    $session = ApiAuthService::getAuthenticatedUser();

    if (!$email || !$session) {
        return JsonResponse::createResponse(["exists" => false]);
    }

    $checkOwnerId = $session->getIdOwner();
    
    if ($session->getLevel() === 4) {
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $userInstitutionService = new \App\Services\UserInstitutionService();

        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $checkOwnerId = $institution->id_owner;
            }
        }

        if (!$checkOwnerId) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($session->getId());
            if ($primaryInstitution) {
                $institution = $institutionRepo->getById($primaryInstitution->institution_id);
                if ($institution && $institution->id_owner) {
                    $checkOwnerId = $institution->id_owner;
                }
            }
        }

        if (!$checkOwnerId) {
            return JsonResponse::createResponse(["exists" => false]);
        }
    }

    $repo = new UserRepository();
    $client = $session->getLevel() === 4
        ? $repo->getOneWithoutOwnership([
            "email" => $email,
            "level" => 5
        ])
        : $repo->getOne([
            "email" => $email,
            "level" => 5
        ]);

    if (!$client) {
        return JsonResponse::createResponse(["exists" => false]);
    }

    $assocRepo = new ClientsUsersRepository();
    $isLinked = $assocRepo->exists($client->id, $checkOwnerId);
    if (!$isLinked && (int)($client->id_owner ?? 0) === (int)$checkOwnerId) {
        $isLinked = true;
    }

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
