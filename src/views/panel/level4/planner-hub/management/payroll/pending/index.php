<?php

use App\Repositories\UserRepository;
use App\Repositories\PayrollHoursRepository;
use App\Services\LoginService;
use App\Services\TimeService;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    try {
        $user = LoginService::getSession();
        if (!$user) {
            \App\Utils\LocationUtils::redirectInternal("/login");
        }
        
        $repoHours = new PayrollHoursRepository();
        $repoUsers = new UserRepository();

        $employeeId = $_GET["user_id"] ?? null;
        $from = $_GET["from"] ?? "";
        $to = $_GET["to"] ?? "";

        $currentOwnerId = $user->getId();
        $institutionOwnerId = null;
        
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                $institutionOwnerId = $institution ? $institution->id_owner : null;
                $currentOwnerId = $institutionOwnerId ?? $currentOwnerId;
            }
        }
        
        $hours = $repoHours->getHistoryWithUserAndStatus($from, $to, $employeeId, idOwner: $currentOwnerId);
        $grouped = [];

        foreach ($hours as $h) {
            if ($h->start_time && $h->end_time) {
                try {
                    $start = new DateTime($h->start_time);
                    $end = new DateTime($h->end_time);

                    if ($end > $start) {
                        $diff = $end->diff($start);
                        $totalSeconds = $diff->days * 86400 + $diff->h * 3600 + $diff->i * 60 + $diff->s;
                        $totalHours = $totalSeconds / 3600;

                        $grouped[$h->id_user]["total_hours_sum"] = ($grouped[$h->id_user]["total_hours_sum"] ?? 0) + $totalHours;
                        $grouped[$h->id_user]["email"] = $h->email;
                        $grouped[$h->id_user]["min"][] = $h->start_time;
                        $grouped[$h->id_user]["max"][] = $h->end_time;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        foreach ($grouped as $id => &$data) {
            try {
                $data["user_id"] = $id;
                $data["total_hours"] = round($data["total_hours_sum"] ?? 0, 2);

                if (!empty($data["min"]) && !empty($data["max"])) {
                    $data["from"] = (new DateTime(min($data["min"])))->format("c");
                    $data["to"] = (new DateTime(max($data["max"])))->format("c");
                } else {
                    $data["from"] = "";
                    $data["to"] = "";
                }

                $userData = $repoUsers->getOne(["id" => $id]);
                $data["hourly_rate"] = $userData->hourly_rate ?? null;

                if ($data["hourly_rate"] !== null && $data["total_hours"] > 0) {
                    $data["total_payment"] = round($data["total_hours"] * $data["hourly_rate"], 2);
                } else {
                    $data["total_payment"] = 0;
                }
            } catch (\Exception $e) {
                $data["total_hours"] = 0;
                $data["total_payment"] = 0;
                $data["from"] = "";
                $data["to"] = "";
            }
        }
        unset($data);

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
        
        $employees = [];
        $records = [];
        foreach ($linkedUsers as $linkedUser) {
            $employee = $repoUsers->getOne(["id" => $linkedUser->id]);
            if (!$employee) {
                continue;
            }

            $data = $grouped[$employee->id] ?? [];
            $totalHours = round($data["total_hours"] ?? 0, 2);
            
            // Solo incluir usuarios que tienen horas registradas
            if ($totalHours > 0) {
                $employees[] = $employee;
                $records[] = [
                    "user_id" => $employee->id,
                    "email" => $employee->email,
                    "total_hours" => $totalHours,
                    "from" => $data["from"] ?? "",
                    "to" => $data["to"] ?? "",
                    "total_payment" => $data["total_payment"] ?? 0
                ];
            }
        }
        
        // Para el dropdown de agregar horas manuales, necesitamos todos los usuarios
        $allEmployees = [];
        foreach ($linkedUsers as $linkedUser) {
            $employee = $repoUsers->getOne(["id" => $linkedUser->id]);
            if ($employee) {
                $allEmployees[] = $employee;
            }
        }
        
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "employees" => $employees,
            "allEmployees" => $allEmployees,
            "employeeId" => $employeeId,
            "from" => $from,
            "to" => $to,
            "records" => $records
        ]);
        
    } catch (\Exception $e) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "employees" => [],
            "allEmployees" => [],
            "employeeId" => null,
            "from" => "",
            "to" => "",
            "records" => [],
            "error" => "Error loading payroll data: " . $e->getMessage()
        ]);
    }
});

$router->post(function () {
    $repoHours = new PayrollHoursRepository();
    $user = LoginService::getSession();
    
    $action = $_POST["action"] ?? "";
    
    $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
    $currentOwnerId = $user->getId();
    $currentInstitutionId = null;
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && isset($institution->id_owner)) {
                $currentOwnerId = $institution->id_owner;
            }
        }
    } else {
        $ownerInstitution = $institutionRepo->getByOwner($user->getId());
        if ($ownerInstitution) {
            $currentInstitutionId = $ownerInstitution->id;
            $currentOwnerId = $ownerInstitution->id_owner ?? $currentOwnerId;
        }
    }
    
    if ($action === "addManualHour") {
        $userId = isset($_POST["user_id"]) ? (int)$_POST["user_id"] : 0;
        $startInput = $_POST["manual_start_time"] ?? "";
        $endInput = $_POST["manual_end_time"] ?? "";
        $notes = trim($_POST["manual_notes"] ?? "");
        
        if (!$userId || !$startInput || !$endInput) {
            \App\Utils\MessageUtil::setMessage("Please provide start time, end time, and a valid user.");
            \App\Utils\LocationUtils::reload();
        }
        
        $startTime = str_replace('T', ' ', $startInput) . ':00';
        $endTime = str_replace('T', ' ', $endInput) . ':00';
        
        if (strtotime($endTime) <= strtotime($startTime)) {
            \App\Utils\MessageUtil::setMessage("End time must be later than start time.");
            \App\Utils\LocationUtils::reload();
        }
        
        $created = $repoHours->createManualHour($userId, $currentOwnerId, $startTime, $endTime, $notes ?: null);
        
        if ($created) {
            \App\Utils\MessageUtil::setMessage("Manual hours added successfully.");
        } else {
            \App\Utils\MessageUtil::setMessage("Unable to add manual hours.", "Error", "error");
        }
        
        \App\Utils\LocationUtils::redirectInternal("panel/planner-hub/management/payroll/pending");
    }
});

try {
    $router->run();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}