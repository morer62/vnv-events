<?php

use App\Repositories\PayrollPaymentsRepository;
use App\Services\LoginService;
use App\Services\TimeService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $id = $_GET["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Payment ID not provided."); 
        LocationUtils::redirectInternal("panel/planner-hub/management/payroll/paid");
    }

    $repo = new PayrollPaymentsRepository();
    $rows = $repo->getHoursForPayment($id);

    $totalDiff = [];

    foreach ($rows as $h) {
        $h->total_hours = TimeService::getDateLocalDiff($h->start_time, $h->end_time);
        $totalDiff[] = TimeService::getDateDiff($h->start_time, $h->end_time);
    }

    $total = TimeService::getDateLocalDiffFromInterval(TimeService::sumAllIntervals($totalDiff));

    return TemplateResponse::render(__DIR__ . "/index.twig", data: [
        "hours" => $rows,
        "total" => $total
    ]);
});

$router->run();
