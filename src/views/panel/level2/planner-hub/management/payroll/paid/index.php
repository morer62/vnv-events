<?php

use App\Repositories\PayrollPaymentsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\TimeService;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $paymentsRepo = new PayrollPaymentsRepository();
    $usersRepo = new UserRepository();

    $employeeId = $_GET["user_id"] ?? null;
    $from = $_GET["from"] ?? "";
    $to = $_GET["to"] ?? "";

    $currentOwnerId = $user->getId();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            $currentOwnerId = $institutionOwnerId ?? $currentOwnerId;
        }
    }
    
    $payments = $paymentsRepo->getGroupedPayments($currentOwnerId, $employeeId, $from, $to);
    foreach ($payments as &$p) {
        $hours = $paymentsRepo->getHoursForPayment($p->id);
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

    $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
    $institutionIdToUse = $user->getId();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionIdToUse = $currentInstitutionId;
        }
    } else {
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $userInstitution = $institutionRepo->getByOwner($user->getId());
        if ($userInstitution) {
            $institutionIdToUse = $userInstitution->id;
        }
    }
    
    $linkedUsers = $userInstitutionsRepo->getUsersForInstitution($institutionIdToUse);
    $employees = array_map(function($lu) use ($usersRepo) { 
        return $usersRepo->getOne(["id" => $lu->id]); 
    }, array_filter($linkedUsers));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "payments" => $payments,
        "employees" => $employees,
        "from" => $from,
        "to" => $to,
        "employeeId" => $employeeId,
    ]);
});

$router->run();
