<?php

use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\ClientsUsersRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;

$id = $_GET["id"] ?? null;
$user = LoginService::getSession();

$orderRepo = new OrdersRepository();
$clientRepo = new UserRepository();
$contractRepo = new OrdersContractRepository();
$servicesRepo = new OrdersServicesAssignedRepository();
$tasksRepo = new OrdersServiceTasksRepository();
$teamTaskRepo = new OrdersTeamTasksRepository();
$clientsRepo = new ClientsUsersRepository();

if (!$id) {
    MessageUtil::setMessage("Invalid order ID.");
    LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
}

// Traer la orden sin restricciones de ownership
$orderArray = $orderRepo->getByIdWithoutOwnershipCheck((int)$id);
$order = $orderArray ? (object) $orderArray : null;

if (!$order) {
    MessageUtil::setMessage("Order not found.");
    LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
}

// Validar acceso: si el cliente es dueño o está asociado
$ownerIds = $clientsRepo->getOwnerIdsForClient($user->getId());

if ($order->id_client !== $user->getId() && !in_array($order->id_owner, $ownerIds)) {
    MessageUtil::setMessage("You don't have access to this order.");
    LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
}

$client = $clientRepo->getOne(["id" => $order->id_client]);
$contract = $contractRepo->getOne(["id" => $order->id_contract]);
$services = $servicesRepo->getAllBy(["id_order" => $order->id]);

$tasksByService = [];
foreach ($services as $srv) {
    $tasksByService["$srv->id_service"] = $tasksRepo->getAllBy([
        "id_service" => $srv->id_service
    ]);
}

$teamTasks = $teamTaskRepo->getAllBy([
    "id_order" => $order->id
]);

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "order" => $order,
    "client" => $client,
    "contract" => $contract,
    "services" => $services,
    "tasksByService" => $tasksByService,
    "teamTasks" => $teamTasks,
    "id" => $id
]);
