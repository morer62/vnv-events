<?php

use App\Repositories\UserRepository;
use App\Repositories\PayrollHoursRepository;
use App\Services\LoginService;
use App\Services\TimeService;
use App\Utils\DateUtil;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    
 
    
    $repoHours = new PayrollHoursRepository();
    $employeeId = $user->getId();

    $allHours = $repoHours->getAllUnpaidByUser($employeeId);

    $allHours = array_map(function ($hour) {
        $hour->total_hours = TimeService::getDateLocalDiff($hour->start_time, $hour->end_time);
        return $hour;
    }, $allHours);

    $groupedByInstitution = [];
    foreach ($allHours as $hour) {
        $institutionName = $hour->institution_name ?? 'No Institution';
        if (!isset($groupedByInstitution[$institutionName])) {
            $groupedByInstitution[$institutionName] = [];
        }
        $groupedByInstitution[$institutionName][] = $hour;
    }

    $institutionSummaries = [];
    $grandTotalSessions = 0;
    $grandTotalHours = 0;

    foreach ($groupedByInstitution as $institutionName => $hours) {
        $totalHoursDiff = [];
        foreach ($hours as $hour) {
            $totalHoursDiff[] = TimeService::getDateDiff($hour->start_time, $hour->end_time);
        }

        $totalSeconds = 0;
        foreach ($totalHoursDiff as $interval) {
            $start = new DateTimeImmutable('@0');
            $end = $start->add($interval);
            $totalSeconds += $end->getTimestamp();
        }
        $totalHoursDecimal = round($totalSeconds / 3600, 2);

        $institutionSummaries[$institutionName] = [
            'sessions' => count($hours),
            'hours' => $totalHoursDecimal
        ];

        $grandTotalSessions += count($hours);
        $grandTotalHours += $totalHoursDecimal;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "groupedByInstitution" => $groupedByInstitution,
        "institutionSummaries" => $institutionSummaries,
        "grandTotalSessions" => $grandTotalSessions,
        "grandTotalHours" => $grandTotalHours,
        "user" => $user
    ]);
});

function editTime(): never {
    $payrollHoursRepository = new PayrollHoursRepository();

    $id = $_POST["id"] ?? null;
    $field = $_POST["action"] == "editStatDate" ? "start_time" : "end_time";
    $value = $_POST["date"] ?? "";
    $timezone = $_POST["userTimezone"] ?? "";

    if (!$id || !$value || !$timezone) {
        MessageUtil::setMessage("Invalid record ID or date.");
        LocationUtils::reload();
    }

    $value = DateUtil::convertToUtcTime("$value:00", $timezone);
    $payrollHoursRepository->update([$field => $value], [
        "id" => $id
    ]);

    MessageUtil::setMessage("Time updated successfully.");
    LocationUtils::reload();
}

$router->post(function () {
    $action = $_POST["action"] ?? "";
    if ($action === "editStatDate" || $action === "editEndDate") {
        editTime();
    }

    MessageUtil::setMessage("Action not found.");
    LocationUtils::reload();
});

$router->run();