<?php

use App\Repositories\OrdersServiceTasksRepository;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(callback: function () {
    $id = $_GET["id"] ?? 0;
    $serviceId = $_GET["service_id"] ?? 0;

    $repo = new OrdersServiceTasksRepository();
    $task = $repo->getOne(["id" => $id]);

    return TemplateResponse::render(templateLocation: __DIR__ . "/index.twig", data: [
        "task" => $task,
        "service_id" => $serviceId
    ]);
});

$router->post(callback: function () {
    $id = $_POST["id"] ?? 0;
    $serviceId = $_POST["service_id"] ?? 0;
    $taskName = trim($_POST["task_name"] ?? "");

    if ($taskName === "") {
        MessageUtil::setMessage("Task name is required.");
        LocationUtils::reload();
    }

    $repo = new OrdersServiceTasksRepository();
    $repo->update(["task_name" => $taskName], ["id" => $id]);

    LocationUtils::redirectInternal("panel/planner-hub/management/orders/services/service-tasks/?service_id=" . $serviceId);
});

$router->run();
