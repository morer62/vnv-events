<?php

use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Repositories\UserRepository;
use App\Services\LoginService;


$router = new Router();

$router->get(function () {
    $repo = new OrdersServiceRepository(); 
    $user = LoginService::getSession();

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            
            if ($institution && $institution->id_owner) {
                $services = $repo->getAllByInstitutionOwner($institution->id_owner, 0);
            } else {
                $services = [];
            }
        } else {
            $services = [];
        }
    } else {
        $services = $repo->getAllBy([
            "id_owner" => $user->getOwner(),
            "is_archived" => 0,
        ]);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "services" => $services
    ]);
});

$router->post(function () {
    $id = $_POST["id"] ?? null;
    $repo = new OrdersServiceRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $ordersRepo = new OrdersRepository();
    $subAssignedRepo = new OrderSuborderServicesAssignedRepository();
    $subRepo = new OrdersSuborderRepository();

    if (!$id) {
        MessageUtil::setMessage("Invalid service ID.");
        LocationUtils::reload();
    }

    if (($_POST["action"] ?? "") === "duplicate") {
        $user = LoginService::getSession();
        
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                if ($institution && $institution->id_owner) {
                    $service = $repo->getOneByIdAndOwner($id, $institution->id_owner);
                } else {
                    $service = null;
                }
            } else {
                $service = null;
            }
        } else {
            $service = $repo->getOne(["id" => $id]);
        }
        
        if (!$service) {
            MessageUtil::setMessage("Service not found.");
            LocationUtils::reload();
        }

        $duplicateData = [
            "name" => $service->name . " (Duplicated)",
            "price" => $service->price,
            "description" => $service->description,
            "requirements" => $service->requirements,
            "description_url" => $service->description_url,
            "is_variable" => $service->is_variable ?? 'NO',
            "id_owner" => $service->id_owner,
            "is_archived" => 0
        ];

        error_log("Duplicating service data: " . json_encode($duplicateData));
        
        if ($user->getLevel() === 4) {
            $success = $repo->addWithExplicitOwner($duplicateData);
        } else {
            $newId = $repo->add($duplicateData);
            $success = (bool)$newId;
        }
        
        if ($success) {
            MessageUtil::setMessage("Service duplicated successfully.");
        } else {
            error_log("Failed to duplicate service. Repository add returned false.");
            MessageUtil::setMessage("Failed to duplicate service. Check logs for details.");
        }
        LocationUtils::reload();
    }

    // Si acción es duplicar, ya manejado arriba

    // Advertencia antes de archivar
    $messages = [];
    $asa = $assignedRepo->getAllWithoutOwner(["id_service" => (int)$id]);
    $pending = 0;
    foreach ($asa as $row) {
        $order = $ordersRepo->getOne(["id" => $row->id_order]);
        if ($order) {
            $status = trim($order->status_workflow);
            if (in_array($status, ['INVOICE_READY','INVOICE_PARTIAL','INVOICE_PAID'], true)) { $pending++; }
        }
    }
    $subAssigned = $subAssignedRepo->getAllBy(["id_service" => (int)$id]);
    foreach ($subAssigned as $row) {
        $sub = $subRepo->getOne(["id" => $row->id_suborder]);
        if ($sub) {
            $status = trim($sub->status_workflow);
            if (in_array($status, ['INVOICE_READY','INVOICE_PARTIAL','INVOICE_PAID'], true)) { $pending++; }
        }
    }

    if ($pending > 0 && empty($_POST['confirm_pending'])) {
        // Renderizar la tabla con un modal de confirmación
        $user = LoginService::getSession();
        
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                if ($institution && $institution->id_owner) {
                    $services = $repo->getAllByInstitutionOwner($institution->id_owner, 0);
                } else {
                    $services = [];
                }
            } else {
                $services = [];
            }
        } else {
            $services = $repo->getAllBy([
                "id_owner" => $user->getOwner(),
                "is_archived" => 0,
            ]);
        }

        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "services" => $services,
            "pending_modal_open" => true,
            "pending_modal_service_id" => (int)$id,
            "pending_modal_count" => $pending
        ]);
    }

    if ($pending > 0) {
        $messages[] = "⚠️ This service is used by $pending active order(s) (READY/PARTIAL/PAID).";
    }

    $repo->archive((int)$id);

    $messages[] = "Service deleted successfully.";
    MessageUtil::setMessage(implode(' ', $messages));
    LocationUtils::reload();
});

$router->run();
