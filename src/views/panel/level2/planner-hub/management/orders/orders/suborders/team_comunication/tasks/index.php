<?php

use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Services\NotificationService;

$router = new Router();

function suborders_team_path(): string {
    return 'panel/planner-hub/management/orders/orders/suborders/team_comunication/';
}

$router->get(function () {
    $user = LoginService::getSession();
    $suborderId = $_GET["id"] ?? null;
    
    if (!$suborderId) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/suborders/');
    }

    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();
    $assignedRepo = new OrderSuborderServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();
    $taskRepo = new OrdersServiceTasksRepository();
    $teamTaskRepo = new OrdersTeamTasksRepository();
    $userRepo = new UserRepository();

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/suborders/');
    }

    $order = $orderRepo->getOne([
        'id' => $suborder->id_order,
        'id_owner' => $user->getOwner()
    ]);

    if (!$order) {
        MessageUtil::setMessage('❌ Sub-order not accessible.');
        LocationUtils::redirectInternal('panel/planner-hub/management/orders/orders/suborders/');
    }

    $servicesAssigned = $assignedRepo->getAllBy(["id_suborder" => $suborderId]);
    $services = [];

    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);

        foreach ($tasks as $task) {
            $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
                "id_suborder" => $suborderId,
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
            "tasks" => $tasks
        ];
    }

    $manualTasks = $teamTaskRepo->getAllBy([
        "id_suborder" => $suborderId,
        "id_service" => 0
    ]);

    $confirmedMembers = $userRepo->getConfirmedMembersForSuborder($suborderId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "suborder_id" => $suborderId,
        "order_id" => $order->id,
        "services" => $services,
        "members" => $confirmedMembers,
        "manualTasks" => $manualTasks
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $suborderId = $_POST["suborder_id"] ?? null;
    $formType = $_POST["form_type"] ?? null;
    $teamTaskRepo = new OrdersTeamTasksRepository();
    $suborderRepo = new OrdersSuborderRepository();

    if (!$suborderId) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::reload();
    }

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::reload();
    }

    if ($formType === "manual_task_add") {
        if (!empty($_POST["description"]) && !empty($_POST["id_user"])) {
            $teamTaskRepo->add([
                "id_suborder" => $suborderId,
                "id_service" => 0,
                "task_description" => trim($_POST["description"]),
                "id_user" => intval($_POST["id_user"]),
                "id_owner" => $user->getOwner()
            ]);

            NotificationService::sendToUsers(
                [$_POST["id_user"]],
                '✅ New Task Assigned',
                'You have been assigned 1 new task for Sub-Order #' . $suborderId . '.'
            );
        }

        LocationUtils::reload();
    }

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

        if ($description && $id_service && !empty($id_user)) {
            $existing = $teamTaskRepo->getOneWithoutOwnershipCheck([
                "id_suborder" => $suborderId,
                "id_service" => $id_service,
                "task_description" => $description
            ]);

            if ($existing) {
                // Actualizar la asignación existente
                $teamTaskRepo->update(
                    ["id_user" => $id_user],
                    ["id" => $existing->id]
                );
            } else {
                // Crear nueva asignación
                $teamTaskRepo->add([
                    "id_suborder" => $suborderId,
                    "id_user" => $id_user,
                    "task_description" => $description,
                    "id_service" => $id_service,
                    "id_owner" => $user->getOwner()
                ]);
            }
        }
        LocationUtils::reload();
    }

    // TAREAS AUTOMÁTICAS (Ya no se usa, pero lo dejo por compatibilidad)
    if ($formType === "auto_tasks") {
        $assignedCountPerUser = [];

        if (isset($_POST["assignments"]) && is_array($_POST["assignments"])) {
            foreach ($_POST["assignments"] as $task) {
                $description = $task["description"] ?? null;
                $id_user = $task["id_user"] ?? null;
                $id_service = $task["id_service"] ?? null;

                // Solo procesar si hay descripción, servicio Y usuario seleccionado
                if ($description && $id_service && !empty($id_user)) {
                    $existing = $teamTaskRepo->getOneWithoutOwnershipCheck([
                        "id_suborder" => $suborderId,
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
                    } else {
                        // Si no existe, crear nueva asignación
                        $teamTaskRepo->add([
                            "id_suborder" => $suborderId,
                            "id_user" => $id_user,
                            "task_description" => $description,
                            "id_service" => $id_service,
                            "id_owner" => $user->getOwner()
                        ]);

                        if (!isset($assignedCountPerUser[$id_user])) {
                            $assignedCountPerUser[$id_user] = 0;
                        }
                        $assignedCountPerUser[$id_user]++;
                    }
                }
                // Si no hay usuario seleccionado, simplemente ignorar (no borrar asignaciones existentes)
            }
        }

        foreach ($assignedCountPerUser as $uid => $count) {
            NotificationService::sendToUsers(
                [$uid],
                '✅ New Tasks Assigned',
                'You have been assigned ' . $count . ' new task' . ($count > 1 ? 's' : '') . ' for Sub-Order #' . $suborderId . '.'
            );
        }

        LocationUtils::reload();
    }

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
