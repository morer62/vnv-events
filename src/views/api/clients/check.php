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
    $email = trim((string)($_GET["email"] ?? ""));
    $session = LoginService::getSession();

    if (!$email || !$session) {
        return JsonResponse::createResponse(["exists" => false]);
    }

    try {
        $checkOwnerId = (int)($session->getOwner() ?: $session->getIdOwner() ?: $session->getId());
    
        if ((int)$session->getLevel() === 4) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $userInstitutionService = new \App\Services\UserInstitutionService();

            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institution = $institutionRepo->getById($currentInstitutionId);
                if ($institution && $institution->id_owner) {
                    $checkOwnerId = (int)$institution->id_owner;
                }
            }

            if (!$checkOwnerId) {
                $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($session->getId());
                if ($primaryInstitution) {
                    $institution = $institutionRepo->getById($primaryInstitution->institution_id);
                    if ($institution && $institution->id_owner) {
                        $checkOwnerId = (int)$institution->id_owner;
                    }
                }
            }

            if (!$checkOwnerId) {
                return JsonResponse::createResponse(["exists" => false]);
            }
        }

        $repo = new UserRepository();
        $client = $repo->getOneWithoutOwnership([
            "email" => $email,
            "level" => 5
        ]);

        if (!$client) {
            return JsonResponse::createResponse(["exists" => false]);
        }

        $assocRepo = new ClientsUsersRepository();
        $isLinked = $assocRepo->exists((int)$client->id, $checkOwnerId);
        if (!$isLinked && (int)($client->id_owner ?? 0) === $checkOwnerId) {
            $isLinked = true;
        }

        return JsonResponse::createResponse([
            "exists" => true,
            "is_linked" => $isLinked,
            "id" => $client->id,
            "name" => trim(($client->name ?? "") . " " . ($client->lastname ?? "")),
            "email" => $client->email,
            "phone" => $client->phone ?? ""
        ]);
    } catch (Throwable $e) {
        error_log("[clients/check] " . $e->getMessage());
        return JsonResponse::createResponse([
            "exists" => false,
            "error" => true,
            "message" => "Unable to check client right now."
        ]);
    }
});



$router->run();
