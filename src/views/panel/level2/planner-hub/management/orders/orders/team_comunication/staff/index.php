<?php

use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersStaffInvitesRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(callback: function () {
    $id = $_GET["id"] ?? null;
    if (!$id) die("Missing order ID");

    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $usersRepo = new UserRepository();
    $invitesRepo = new OrdersStaffInvitesRepository();
    $userInstitutionsRepo = new UserInstitutionsRepository();

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if (!$currentInstitutionId) {
            die("❌ No institution selected.");
        }

        $institutionRepo = new InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        if (!$institution) {
            die("❌ Institution not found.");
        }

        $institutionOwnerId = $institution->id_owner;
        $order = $orderRepo->getOneByIdAndOwner($id, $institutionOwnerId);
        $institutionId = $currentInstitutionId;
    } else {
        $order = $orderRepo->getOne(["id" => $id]); 
        
        $institutionRepo = new InstitutionProfileRepository();
        $userInstitution = $institutionRepo->getByOwner($user->getIdOwner());
        $institutionId = $userInstitution ? $userInstitution->id : null;
    }

    if (!$order) {
        die("❌ Order not found or not accessible.");
    }
    
    if ($institutionId) {
        $allStaff = $userInstitutionsRepo->getUsersForInstitution($institutionId, ["level" => 4]);
    } else {
        $allStaff = [];
    }

    $invited = $invitesRepo->getInvitesByOrder($id);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "staff" => $allStaff,
        "invited" => $invited,
    ]);
});

$router->post(callback: function () {
    $id = $_GET["id"] ?? null;
    if (!$id) die("Missing order ID");

    $invitesRepo = new OrdersStaffInvitesRepository();
    $id_user = $_POST["id_user"] ?? null;
    $action = $_POST["action"] ?? null;

    if ($id_user && $action === "invite") {
        // Verificar si ya está invitado antes de invitar
        $existingInvite = $invitesRepo->getInvite($id, $id_user);
        if (!$existingInvite) {
            $invitesRepo->inviteUser($id, $id_user);
        }
    }

    if ($id_user && $action === "remove") {
        $invitesRepo->removeInvite($id, $id_user);
    }

    header("Location: ?id=$id");
    exit;
});

$router->run();
