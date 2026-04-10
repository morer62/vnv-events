<?php

use App\Repositories\ClientsUsersRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->post(function () {
    $session = LoginService::getSession();
    $body = json_decode(file_get_contents("php://input"), true);
    $clientId = (int) ($body["id"] ?? 0);

    if (!$clientId || !$session) {
        return JsonResponse::createResponse(["success" => false, "message" => "Invalid client ID or session."]);
    }

    $associateOwnerId = $session->getIdOwner();
    
    if ($session->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $associateOwnerId = $institution->id_owner;
            }
        }
    }

    $assocRepo = new ClientsUsersRepository();
    $userRepo = new UserRepository();

    if ($assocRepo->exists($clientId, $associateOwnerId)) {
        return JsonResponse::createResponse(["success" => false, "message" => "Client is already associated."]);
    }

    $assocRepo->create($clientId, $associateOwnerId);

    $client = $userRepo->getOne(["id" => $clientId]);

    return JsonResponse::createResponse([
        "success" => true,
        "id" => $clientId,
        "name" => $client->name,
        "lastname" => $client->lastname,
        "email" => $client->email,
        "phone" => $client->phone
    ]);
});

$router->run();
