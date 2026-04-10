<?php

use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;
use App\Services\NotificationService;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();

    if ($context["level"] === 3 && !$context["can"]("orders", "basic_settings")) {
        LocationUtils::redirectInternal("panel/no-access");
        return "";
    }

    $orderId = $_GET["id"] ?? null;
    if (!$orderId) LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");

    $user = LoginService::getSession();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $serviceRepo = new OrdersServiceRepository();
    $taskRepo = new OrdersServiceTasksRepository();
    $teamTaskRepo = new OrdersTeamTasksRepository();
    $userRepo = new UserRepository();

    // Servicios de la orden principal
    $servicesAssigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
    $services = [];

    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);

        foreach ($tasks as $task) {
            $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
                "id_order" => $orderId,
                "id_service" => $assigned->id_service,
                "task_description" => $task->task_name
            ]);

            $task->assigned_id_user = $assignedTask?->id_user;

            if ($assignedTask?->id_user) {
                $assignedUser = $userRepo->getOne(["id" => $assignedTask->id_user]);
                $task->assigned_user_name = $assignedUser ? $assignedUser->name . ' ' . $assignedUser->lastname : null;
            } else {
                $task->assigned_user_name = null;
            }

            $task->id_task = $assignedTask?->id;
            $task->is_done = $assignedTask?->is_done ?? 0;
        }

        $services[] = [
            "name" => $service ? $service->name : "Unknown Service",
            "id_service" => $assigned->id_service,
            "tasks" => $tasks,
            "source" => "main_order"
        ];
    }

    // Servicios de las subórdenes
    $suborders = $suborderRepo->getByOrder($orderId);
    $suborderServices = [];
    
    foreach ($suborders as $suborder) {
        $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        
        foreach ($suborderServicesAssigned as $assigned) {
            $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
            $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);

            foreach ($tasks as $task) {
                $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
                    "id_suborder" => $suborder->id,
                    "id_service" => $assigned->id_service,
                    "task_description" => $task->task_name
                ]);

                $task->assigned_id_user = $assignedTask?->id_user;

                if ($assignedTask?->id_user) {
                    $assignedUser = $userRepo->getOne(["id" => $assignedTask->id_user]);
                    $task->assigned_user_name = $assignedUser ? $assignedUser->name . ' ' . $assignedUser->lastname : null;
                } else {
                    $task->assigned_user_name = null;
                }

                $task->id_task = $assignedTask?->id;
                $task->is_done = $assignedTask?->is_done ?? 0;
            }

            $suborderServices[] = [
                "name" => $service ? $service->name : ($assigned->service_name ?? "Unknown Service"),
                "id_service" => $assigned->id_service,
                "tasks" => $tasks,
                "source" => "suborder",
                "suborder_id" => $suborder->id
            ];
        }
    }

    $manualTasks = $teamTaskRepo->getAllBy([
        "id_order" => $orderId,
        "id_service" => 0
    ]);

    $members = $userRepo->getConfirmedMembersForOrder($orderId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order_id" => $orderId,
        "services" => $services,
        "suborderServices" => $suborderServices,
        "suborders" => $suborders,
        "members" => $members,
        "manualTasks" => $manualTasks,
        ...$context
    ]);
});

$router->post(function () {
    $context = UserContext::get();

    if ($context["level"] === 3 && !$context["can"]("orders", "basic_settings")) {
        LocationUtils::redirectInternal("panel/no-access");
        return "";
    }

    $orderId = $_POST["order_id"];
    $formType = $_POST["form_type"] ?? null;
    $user = LoginService::getSession();
    $teamTaskRepo = new OrdersTeamTasksRepository();

    // AGREGAR TAREA MANUAL
    if ($formType === "manual_task_add") {
        if (!empty($_POST["description"]) && !empty($_POST["id_user"])) {
            $teamTaskRepo->add([
                "id_order" => $orderId,
                "id_service" => 0,
                "task_description" => trim($_POST["description"]),
                "id_user" => intval($_POST["id_user"]),
                "id_owner" => $user->getOwner()
            ]);

            // 🔔 Notificar al miembro asignado (1 sola tarea)
            NotificationService::sendToUsers(
                [$_POST["id_user"]],
                '✅ New Task Assigned',
                'You have been assigned 1 new task for Order VNV 341' . $orderId . '.'
            );

        }

        LocationUtils::reload();
    }

    // ELIMINAR TAREA MANUAL
    if ($formType === "manual_task_delete" && !empty($_POST["delete_manual_task_id"])) {
        $teamTaskRepo->delete(["id" => $_POST["delete_manual_task_id"]]);
        LocationUtils::reload();
    }

    // ELIMINAR ASIGNACIÓN INDIVIDUAL
    if ($formType === "remove_single_task" && !empty($_POST["task_id"])) {
        $teamTaskRepo->delete(["id" => $_POST["task_id"]]);
        LocationUtils::reload();
    }

    // ASIGNAR TAREA INDIVIDUAL
    if ($formType === "assign_single_task") {
        $description = $_POST["task_description"] ?? null;
        $id_user = $_POST["id_user"] ?? null;
        $id_service = $_POST["id_service"] ?? null;
        $suborder_id = $_POST["suborder_id"] ?? null;
        $task_source = $_POST["task_source"] ?? "main_order";

        if ($description && $id_service && !empty($id_user)) {
            if ($task_source === "suborder" && $suborder_id) {
                // Tarea de suborden
                $existing = $teamTaskRepo->getOneWithoutOwnershipCheck([
                    "id_suborder" => $suborder_id,
                    "id_service" => $id_service,
                    "task_description" => $description
                ]);

                if ($existing) {
                    $teamTaskRepo->update(
                        ["id_user" => $id_user],
                        ["id" => $existing->id]
                    );
                } else {
                    $teamTaskRepo->add([
                        "id_suborder" => $suborder_id,
                        "id_user" => $id_user,
                        "task_description" => $description,
                        "id_service" => $id_service,
                        "id_owner" => $user->getOwner()
                    ]);
                }
            } else {
                // Tarea de orden principal
                $existing = $teamTaskRepo->getOneWithoutOwnershipCheck([
                    "id_order" => $orderId,
                    "id_service" => $id_service,
                    "task_description" => $description
                ]);

                if ($existing) {
                    $teamTaskRepo->update(
                        ["id_user" => $id_user],
                        ["id" => $existing->id]
                    );
                } else {
                    $teamTaskRepo->add([
                        "id_order" => $orderId,
                        "id_user" => $id_user,
                        "task_description" => $description,
                        "id_service" => $id_service,
                        "id_owner" => $user->getOwner()
                    ]);
                }
            }
        }
        LocationUtils::reload();
    }

    // TAREAS AUTOMÁTICAS (Ya no se usa, pero lo dejo por compatibilidad)
    if ($formType === "auto_tasks") {
        $assignedCountPerUser = []; // user_id => cantidad

        if (isset($_POST["assignments"]) && is_array($_POST["assignments"])) {
            foreach ($_POST["assignments"] as $index => $task) {
                $description = $task["description"] ?? null;
                $id_user = $task["id_user"] ?? null;
                $id_service = $task["id_service"] ?? null;

                // Solo procesar si hay descripción, servicio Y usuario seleccionado
                if ($description && $id_service && !empty($id_user)) {
                    $existing = $teamTaskRepo->getOneWithoutOwnershipCheck([
                        "id_order" => $orderId,
                        "id_service" => $id_service,
                        "task_description" => $description
                    ]);

                    if ($existing) {
                        // Si existe y el usuario cambió, actualizar
                        if ($existing->id_user != $id_user) {
                            $teamTaskRepo->update(
                                ["id_user" => $id_user],
                                ["id" => $existing->id]
                            );
                        }
                        // Si el usuario es el mismo, no hacer nada (ya está asignada)
                    } else {
                        // Si no existe, crear nueva asignación
                        $teamTaskRepo->add([
                            "id_order" => $orderId,
                            "id_user" => $id_user,
                            "task_description" => $description,
                            "id_service" => $id_service,
                            "id_owner" => $user->getOwner()
                        ]);

                        // Sumar al contador del usuario para notificación
                        if (!isset($assignedCountPerUser[$id_user])) {
                            $assignedCountPerUser[$id_user] = 0;
                        }
                        $assignedCountPerUser[$id_user]++;
                    }
                }
                // Si no hay usuario seleccionado, simplemente ignorar (no borrar asignaciones existentes)
            }
        }

        // 🔔 Enviar notificaciones por usuario (una sola por persona)
        foreach ($assignedCountPerUser as $uid => $count) {
            NotificationService::sendToUsers(
                [$uid],
                '✅ New Tasks Assigned',
                'You have been assigned ' . $count . ' new task' . ($count > 1 ? 's' : '') . ' for Order VNV 341' . $orderId . '.'
            );
        }

        LocationUtils::reload();
    }

    // TOGGLE STATUS DONE
    if ($formType === "toggle_done" && !empty($_POST["task_id"])) {
        $taskId = intval($_POST["task_id"]);
        $task = $teamTaskRepo->getOne(["id" => $taskId]);

        if ($task) {
            $teamTaskRepo->update(
                ["is_done" => $task->is_done ? 0 : 1],
                ["id" => $taskId]
            );
        }

        LocationUtils::reload();
    }
});

$router->run();
