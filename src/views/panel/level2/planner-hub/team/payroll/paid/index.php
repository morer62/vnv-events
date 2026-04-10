<?php

use App\Repositories\PayrollPaymentsRepository;
use App\Services\LoginService;
use App\Services\TimeService;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    
    if ($user->getLevel() != 4) {
        MessageUtil::setMessage("Access denied.");
        LocationUtils::redirectInternal("panel/planner-hub/");
    }

    $from = $_GET["from"] ?? "";
    $to = $_GET["to"] ?? "";

    $repo = new PayrollPaymentsRepository();
    $payments = $repo->getAllPaymentsByUser($user->getId(), $from, $to);

    // Calcular duración real de horas trabajadas
    foreach ($payments as &$p) {
        $hours = $repo->getHoursForPayment($p->id);
        $intervals = [];

        foreach ($hours as $h) {
            if ($h->start_time && $h->end_time) {
                $intervals[] = TimeService::getDateDiff($h->start_time, $h->end_time);
            }
        }

        $p->total_hours_readable = TimeService::getDateLocalDiffFromInterval(
            TimeService::sumAllIntervals($intervals)
        );
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $payments,
        "from" => $from,
        "to" => $to,
    ]);
});

$router->run();
