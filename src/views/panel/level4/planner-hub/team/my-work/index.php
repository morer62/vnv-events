<?php

use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\StoreDeliveryLocationLogsRepository;
use App\Repositories\StoreOrderTasksRepository;
use App\Repositories\StoreOrderWorkflowRepository;
use App\Repositories\StoreOrdersRepository;
use App\Services\LoginService;
use App\Services\UserWorkspaceContextService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $teamContext = (new UserWorkspaceContextService())->getTeamContext($user);
    $ownerId = (int)($teamContext['selectedOwnerId'] ?? $user->getOwner());

    $serviceTasks = $ownerId > 0
        ? (new OrdersTeamTasksRepository())->getForUserAndOwnerDetailed((int)$user->getId(), $ownerId)
        : [];
    $storeTasks = $ownerId > 0
        ? (new StoreOrderTasksRepository())->getForAssignee($ownerId, (int)$user->getId())
        : [];

    foreach ($storeTasks as $task) {
        $task->can_chat_with_client = (int)($task->allow_chat_with_client ?? 0) === 1
            && (int)$user->getAllowChatWithClients() === 1;
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'teamContext' => $teamContext,
        'serviceTasks' => $serviceTasks,
        'storeTasks' => $storeTasks,
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $teamContext = (new UserWorkspaceContextService())->getTeamContext($user);
    $ownerId = (int)($teamContext['selectedOwnerId'] ?? $user->getOwner());
    $taskId = (int)($_POST['task_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));

    $tasksRepo = new StoreOrderTasksRepository();
    $workflowRepo = new StoreOrderWorkflowRepository();
    $ordersRepo = new StoreOrdersRepository();
    $locationRepo = new StoreDeliveryLocationLogsRepository();
    $task = $tasksRepo->getOneForAssignee($taskId, $ownerId, (int)$user->getId());

    if (!$task) {
        MessageUtil::setMessage('Store task not found or not assigned to your workspace.');
        LocationUtils::reload();
    }

    $lat = trim((string)($_POST['location_lat'] ?? ''));
    $lng = trim((string)($_POST['location_long'] ?? ''));
    $needsLocation = (int)($task->requires_location ?? 0) === 1
        || in_array($action, ['out_for_delivery', 'arrived', 'delivered', 'update_location'], true);

    if ($needsLocation && (!is_numeric($lat) || !is_numeric($lng))) {
        MessageUtil::setMessage('You need to allow location access to start this task.');
        LocationUtils::reload();
    }

    $eventType = 'LOCATION_UPDATE';
    $ok = false;

    if ($action === 'start') {
        $ok = $tasksRepo->updateTaskStatus($taskId, StoreOrderTasksRepository::STATUS_IN_PROGRESS);
        $eventType = 'TASK_START';
    } elseif ($action === 'out_for_delivery') {
        $ok = $tasksRepo->updateTaskStatus($taskId, StoreOrderTasksRepository::STATUS_IN_PROGRESS)
            && $ordersRepo->updateStatus((int)$task->id_store_order, StoreOrdersRepository::STATUS_OUT_FOR_DELIVERY);
        $eventType = 'OUT_FOR_DELIVERY';
    } elseif ($action === 'arrived') {
        $ok = true;
        $eventType = 'ARRIVED';
    } elseif ($action === 'delivered') {
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ((int)($task->allow_team_close_delivery ?? 0) !== 1) {
            $ok = $tasksRepo->updateTaskStatus($taskId, StoreOrderTasksRepository::STATUS_WAITING_REVIEW, null, $notes);
            $eventType = 'ARRIVED';
        } else {
            $photoUrl = '';
            if (FileUtils::hasFile($_FILES, 'delivery_proof')) {
                $file = $_FILES['delivery_proof'];
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                if (!in_array((string)($file['type'] ?? ''), $allowedTypes, true) || (int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
                    MessageUtil::setMessage('Delivery proof must be a JPG, PNG or WEBP image up to 5 MB.');
                    LocationUtils::reload();
                }
                $photoUrl = FileUtils::saveFile($file, 'store-delivery-proofs');
            }
            $ok = $tasksRepo->updateTaskStatus($taskId, StoreOrderTasksRepository::STATUS_COMPLETED, (int)$user->getId(), $notes)
                && $ordersRepo->updateStatus((int)$task->id_store_order, StoreOrdersRepository::STATUS_DELIVERED);
            if ($ok) {
                $workflowRepo->markDeliveryProof((int)$task->id_store_order, (int)$user->getId(), $photoUrl, $notes);
            }
            $eventType = 'DELIVERED';
        }
    } elseif ($action === 'complete') {
        if ((int)($task->allow_assignee_complete ?? 0) !== 1) {
            MessageUtil::setMessage('This task requires admin review before completion.');
            LocationUtils::reload();
        }
        $ok = $tasksRepo->updateTaskStatus($taskId, StoreOrderTasksRepository::STATUS_COMPLETED, (int)$user->getId(), trim((string)($_POST['notes'] ?? '')));
    } elseif ($action === 'update_location') {
        $ok = true;
    }

    if ($ok && is_numeric($lat) && is_numeric($lng)) {
        $locationRepo->addLocation($ownerId, (int)$task->id_store_order, $taskId, (int)$user->getId(), $eventType, (float)$lat, (float)$lng);
        $workflowRepo->updateLatestLocation((int)$task->id_store_order, (int)$user->getId(), (float)$lat, (float)$lng);
    }

    if ($action === 'update_location' && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok]);
        exit;
    }

    MessageUtil::setMessage($ok ? 'Work item updated.' : 'Unable to update this work item.');
    LocationUtils::reload();
});

$router->run();
