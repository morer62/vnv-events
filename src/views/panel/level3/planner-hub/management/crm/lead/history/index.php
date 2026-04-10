<?php

use App\Entity\User;
use App\Repositories\CrmLeadStatusHistoryRepository;
use App\Repositories\CrmLeadRepository;
use App\Repositories\CrmStatusRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();


    $leadId = $_GET["id"] ?? 0;
    $user = LoginService::getSession();

    $historyRepo = new CrmLeadStatusHistoryRepository();
    $statusRepo = new CrmStatusRepository();
    $userRepo = new UserRepository();
    $leadRepo = new CrmLeadRepository();

    $filters = [
    "id_lead" => $leadId
    ];

    // Solo los niveles 4 y 5 deben tener filtro por dueño
    if (in_array($user->getLevel(), User::EXTERNAL_USER_LEVEL)) {
        $filters["id_owner"] = $user->getOwner();
    }

    $history = $historyRepo->getAllBy($filters);


    $statuses = $statusRepo->getAll();

    foreach ($history as &$record) {
        $userData = $record->id_user ? $userRepo->getOne(["id" => $record->id_user]) : null;
        $record->user_name = $userData ? $userData->name : "—";
    }

    $lead = $leadRepo->getOne([
        "id" => $leadId
    ]);

    if (is_null($lead)) {
        MessageUtil::setMessage("Lead not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/crm/lead");
    }

    $leadName = $lead ? $lead->name : "Unknown Lead";

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "history" => $history,
        "lead_id" => $leadId,
        "statuses" => $statuses,
        "lead_name" => $leadName
    ]);
});

$router->post(function () {
    $context = UserContext::get();

   

    $user = LoginService::getSession();
    $leadId = $_GET["id"] ?? null;
    $comment = trim($_POST["comment"] ?? "");

    // ⚠️ Validación defensiva de campo
    if (!isset($_POST["new_status"]) || $_POST["new_status"] === "") {
        MessageUtil::setMessage("New status is required.");
        LocationUtils::reload();
    }

    $newStatusId = (int) $_POST["new_status"];

    if (!$leadId || $newStatusId <= 0) {
        MessageUtil::setMessage("New status is required.");
        LocationUtils::reload();
    }

    $leadRepo = new CrmLeadRepository();
    $statusRepo = new CrmStatusRepository();
    $historyRepo = new CrmLeadStatusHistoryRepository();

    $lead = $leadRepo->getOne(["id" => $leadId]);

   


    $newStatus = $statusRepo->getOne(["id" => $newStatusId]);

    if (!$newStatus) {
        MessageUtil::setMessage("Invalid status selected.");
        LocationUtils::reload();
    }

    // Guardar historial
    $historyRepo->add([
        "id_lead"    => $leadId,
        "old_status" => $lead ? $lead->id_status : null,
        "new_status" => $newStatus->name,
        "comment"    => $comment,
        "id_user"    => $user->getId(),
        "id_owner"   => $user->getOwner()
    ]);

    // Actualizar status del lead
    $leadRepo->update(["id_status" => $newStatus->id], ["id" => $leadId]);

    // Verificar si se debe archivar automáticamente (cuando viene del modal)
    $shouldArchive = isset($_POST["archive_automatically"]) && $_POST["archive_automatically"] === "1";
    
    if ($shouldArchive) {
        // Archivar el lead automáticamente
        $leadRepo->update(["archived" => "YES"], ["id" => $leadId]);
        MessageUtil::setMessage("Lead status updated to " . $newStatus->name . " and archived successfully.");
    } else {
        MessageUtil::setMessage("Lead status updated successfully.");
    }
    
    LocationUtils::reload();
});

$router->run();
