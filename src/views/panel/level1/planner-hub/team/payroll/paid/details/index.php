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
    $user = LoginService::getSession();
    $id = $_GET["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Payment ID not provided."); 
        LocationUtils::redirectInternal("panel/planner-hub/team/payroll/paid");
    }

    $repo = new PayrollPaymentsRepository();
    $payment = $repo->getPaymentDetails((int)$id, null, (int)$user->getId());
    if (!$payment) { MessageUtil::setMessage("Payment not found or access denied."); LocationUtils::redirectInternal("panel/planner-hub/team/payroll/paid"); }
    $rows = $repo->getHoursForPayment($id);

    $totalDiff = [];

    foreach ($rows as $h) {
        $h->total_hours = TimeService::getDateLocalDiff($h->start_time, $h->end_time);
        $h->hours_decimal = round(max(0, strtotime((string)$h->end_time) - strtotime((string)$h->start_time)) / 3600, 2);
        $h->paid_amount = $payment->hourly_rate_effective === null ? null : round($h->hours_decimal * $payment->hourly_rate_effective, 2);
        $totalDiff[] = TimeService::getDateDiff($h->start_time, $h->end_time);
    }

    $total = TimeService::getDateLocalDiffFromInterval(TimeService::sumAllIntervals($totalDiff));

    return TemplateResponse::render(__DIR__ . "/index.twig", data: [
        "hours" => $rows,
        "total" => $total,
        "payment" => $payment,
        "rateHistory" => $repo->getRateHistory((int)$payment->id_user, (int)$payment->id_owner)
    ]);
});

$router->run();
