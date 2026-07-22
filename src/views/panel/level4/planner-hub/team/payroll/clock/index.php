<?php

use App\Services\LoginService;
use App\Repositories\PayrollTimeLogsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Services\NotificationService;
use App\Services\UserInstitutionService;
use App\Services\TeamMemberContractService;
use App\Services\UserWorkspaceContextService;
use App\Services\TranslationService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Repositories\OrdersTeamTasksRepository;

date_default_timezone_set('UTC');

$router = new Router();
$repo = new PayrollTimeLogsRepository();
$user = LoginService::getSession();

function level4ClockRedirect(bool $success = false): never
{
    $clockPath = 'panel/planner-hub/team/payroll/clock' . ($success ? '?clock_updated=1' : '');
    if (($_SESSION['IS_MOBILE_APP'] ?? false) === true) {
        $target = LocationUtils::pathFor($clockPath);
        $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        header('Content-Type: text/html; charset=UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="1;url={$safeTarget}">
  <title>Updating clock</title>
</head>
<body style="margin:0;background:#f7fbfa;color:#102033;font-family:Arial,sans-serif;display:grid;min-height:100vh;place-items:center;">
  <div style="text-align:center;padding:24px;">
    <strong>Updating clock...</strong>
  </div>
  <script>
    window.location.replace("{$safeTarget}");
  </script>
  <noscript>
    <a href="{$safeTarget}">Continue</a>
  </noscript>
</body>
</html>
HTML;
        exit();
    }

    if ($success) {
        LocationUtils::redirectInternal($clockPath);
    }

    LocationUtils::reload();
}

$router->get(function () use ($repo, $user): string {
    $userInstitutionService = new UserInstitutionService();
    $workspaceContextService = new UserWorkspaceContextService();
    $institutionRepo = new InstitutionProfileRepository();
    
    $isLevel4 = $user->getLevel() == 4;
    
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    $currentInstitutionRole = $_SESSION['current_institution_role'] ?? null;
    
    $userInstitutions = [];
    $hasMultipleInstitutions = false;
    
    // Validar que usuarios de nivel 4 tengan al menos una instituciÃƒÂ³n asociada
    if ($isLevel4) {
        $workspaceContextService->getTeamContext($user);
        $userInstitutions = $userInstitutionService->getUserAvailableInstitutions($user->getId());
        
        if (empty($userInstitutions)) {
            MessageUtil::setMessage("Ã¢Å¡Â Ã¯Â¸Â You need to be associated with a company to use the clock. Please contact your administrator to be assigned to a company.");
            LocationUtils::redirectInternal("panel/home");
            exit;
        }
        
        $hasMultipleInstitutions = count($userInstitutions) > 1;
    }
    
    $currentInstitution = null;
    $userInstitutionsRepo = new UserInstitutionsRepository();
    $userInstitutionData = null;
    $roleName = null;
    $hourlyRate = null;
    
    try {
        if ($isLevel4 && $currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            
            if (!$currentInstitution) {
                $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
                if ($primaryInstitution) {
                    $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                    $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                    $_SESSION['current_institution_role'] = 'owner';
                }
            }
        } elseif ($isLevel4) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
            if ($primaryInstitution) {
                $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                $_SESSION['current_institution_role'] = 'owner';
            }
        }
        
        if ($currentInstitution) {
            $userInstitutionData = $userInstitutionsRepo->getUserInstitutionRecord($user->getId(), $currentInstitution->id);
            if ($userInstitutionData) {
                $roleName = $userInstitutionData->role_name ?? 'No Role Assigned';
                $hourlyRate = $userInstitutionData->hourly_rate ?? 0;
            }
        }
    } catch (Exception $e) {
    }

    // Para nivel 4, debe tener una instituciÃƒÂ³n vÃƒÂ¡lida
    if ($isLevel4 && !$currentInstitution) {
        MessageUtil::setMessage("Ã¢Å¡Â Ã¯Â¸Â You need to be associated with a company to use the clock. Please contact your administrator to be assigned to a company.");
        LocationUtils::redirectInternal("panel/home");
        exit;
    }

    $currentInstitutionOwner = $currentInstitution ? $currentInstitution->id_owner : $user->getOwner();

    // Validar que currentInstitutionOwner no sea null para nivel 4
    if ($isLevel4 && !$currentInstitutionOwner) {
        MessageUtil::setMessage("Ã¢Å¡Â Ã¯Â¸Â Unable to determine company owner. Please contact your administrator.");
        LocationUtils::redirectInternal("panel/home");
        exit;
    }

    $logs = $repo->getActiveLogsByUserAndOwner($user->getId(), $currentInstitutionOwner);

    $activeLog = count($logs) > 0 ? $logs[0] : null;
    $recentLogs = $repo->getRecentLogsByUserAndOwner((int)$user->getId(), (int)$currentInstitutionOwner, 30);
    $contractService = new TeamMemberContractService();
    $clockContractStatus = $contractService->getClockContractStatus($user->getId(), (int)$currentInstitutionOwner);

    $assignedEvents = [];
    $assignedOrdersCount = 0;
    $hasAssignedEventsWithoutTasks = false;
    if ($isLevel4 && $currentInstitutionOwner) {
        try {
            $ordersRepo = new OrdersRepository();
            $assignedOrders = array_filter($ordersRepo->getOrdersByInvitation((int)$user->getId()), static function ($order) {
                return (int)($order->is_confirmed ?? 0) === 1;
            });
            $assignedOrdersCount = count($assignedOrders);

            $tasksRepo = new OrdersTeamTasksRepository();
            $taskRows = $tasksRepo->getForUserAndOwnerDetailed((int)$user->getId(), (int)$currentInstitutionOwner);
            $eventsByOrder = [];

            foreach ($taskRows as $taskRow) {
                $orderId = (int)($taskRow->related_order_id ?? $taskRow->id_order ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                if (!isset($eventsByOrder[$orderId])) {
                    $contactName = trim((string)($taskRow->contact_name ?? ''));
                    $eventsByOrder[$orderId] = [
                        'id' => $orderId,
                        'event_date' => $taskRow->event_date ?? null,
                        'start_time' => $taskRow->task_setup_time ?? $taskRow->order_start_time ?? null,
                        'end_time' => $taskRow->order_end_time ?? null,
                        'address' => $taskRow->order_address ?? '',
                        'contact_name' => $contactName !== '' ? $contactName : ($taskRow->contact_email ?? ''),
                        'contact_email' => $taskRow->contact_email ?? '',
                        'tasks' => [],
                    ];
                }

                $taskTitle = trim((string)($taskRow->task_description ?? $taskRow->title ?? $taskRow->name ?? $taskRow->task_title ?? $taskRow->description ?? $taskRow->work_type ?? $taskRow->assigned_service_name ?? ''));
                $eventsByOrder[$orderId]['tasks'][] = [
                    'id' => (int)($taskRow->id ?? 0),
                    'title' => $taskTitle !== '' ? $taskTitle : 'Task',
                    'setup_time' => $taskRow->task_setup_time ?? $taskRow->start_time ?? $taskRow->order_start_time ?? null,
                    'activity_start_time' => $taskRow->task_activity_start_time ?? $taskRow->order_start_time ?? null,
                    'activity_end_time' => $taskRow->task_activity_end_time ?? $taskRow->end_time ?? $taskRow->order_end_time ?? null,
                    'breakdown_time' => $taskRow->task_breakdown_time ?? null,
                    'notes' => $taskRow->notes ?? $taskRow->note ?? $taskRow->instructions ?? '',
                    'is_done' => (int)($taskRow->is_done ?? $taskRow->is_completed ?? 0),
                ];
            }

            $assignedEvents = array_values($eventsByOrder);
            $hasAssignedEventsWithoutTasks = $assignedOrdersCount > count($assignedEvents);
        } catch (Throwable $e) {
            error_log('[Level4 Clock] Assigned events popup failed: ' . $e->getMessage());
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "activeLog" => $activeLog,
        'recentLogs' => $recentLogs,
        'userInstitutions' => $userInstitutions,
        'currentInstitution' => $currentInstitution,
        'currentInstitutionRole' => $currentInstitutionRole,
        'hasMultipleInstitutions' => $hasMultipleInstitutions,
        'currentInstitutionOwner' => $currentInstitutionOwner,
        'isLevel4' => $isLevel4,
        'userInstitutionData' => $userInstitutionData,
        'roleName' => $roleName,
        'hourlyRate' => $hourlyRate,
        'clockContractStatus' => $clockContractStatus,
        'assignedEvents' => $assignedEvents,
        'assignedOrdersCount' => $assignedOrdersCount,
        'hasAssignedEventsWithoutTasks' => $hasAssignedEventsWithoutTasks
    ]);
});

$router->post(callback: function () use ($repo, $user): void {
    if (isset($_POST['switch_institution'])) {
        MessageUtil::setMessage('Team members can only switch between Team and Client views.');
        LocationUtils::redirectInternal('panel/planner-hub/team/payroll/clock');
        return;
    }
    
    $action = $_POST["action"] ?? "";
    $tz = $_POST["tz"] ?? "America/New_York";

    $repo->db->setTimezone($tz);

    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    
    // Para nivel 4, obtener la instituciÃƒÂ³n correcta
    $currentInstitutionOwner = null;
    $isLevel4 = $user->getLevel() == 4;
    
    if ($isLevel4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        
        if ($currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            if ($currentInstitution) {
                $currentInstitutionOwner = $currentInstitution->id_owner;
            }
        }
        
        // Si no hay instituciÃƒÂ³n en sesiÃƒÂ³n, obtener la primaria
        if (!$currentInstitutionOwner) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
            if ($primaryInstitution) {
                $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                if ($currentInstitution) {
                    $currentInstitutionOwner = $currentInstitution->id_owner;
                    $_SESSION['current_institution_id'] = $primaryInstitution->institution_id;
                }
            }
        }
        
        // Validar que tenga instituciÃƒÂ³n antes de continuar
        if (!$currentInstitutionOwner) {
            MessageUtil::setMessage("Ã¢Å¡Â Ã¯Â¸Â You need to be associated with a company to use the clock. Please contact your administrator to be assigned to a company.");
            LocationUtils::redirectInternal("panel/home");
            exit;
        }
    } else {
        $currentInstitutionOwner = $userInstitutionService->getCurrentInstitutionOwner() ?? $user->getOwner();
    }

    $logs = $repo->getActiveLogsByUserAndOwner($user->getId(), $currentInstitutionOwner);

    if ($action === "start") {
        if (count($logs) > 0) {
            MessageUtil::setMessage("You already started a session.");
            level4ClockRedirect();
        }

        $contractService = new TeamMemberContractService();
        if (!$contractService->isClockInAllowed($user->getId(), $currentInstitutionOwner)) {
            MessageUtil::setMessage('You need a signed or approved contract before using the clock.', 'Contract required', 'warning');
            level4ClockRedirect();
        }

        $latitude = trim((string)($_POST["location_lat"] ?? ""));
        $longitude = trim((string)($_POST["location_long"] ?? ""));
        $latitude = is_numeric($latitude) ? $latitude : null;
        $longitude = is_numeric($longitude) ? $longitude : null;

        try {
            $repo->startNow(
                $user->getId(),
                $currentInstitutionOwner,
                $latitude,
                $longitude
            );
        } catch (Throwable $e) {
            error_log('[Level4 Clock] Clock-in failed: ' . $e->getMessage());
            MessageUtil::setMessage('Unable to start the clock. Please try again.', 'Error', 'error');
            level4ClockRedirect();
        }

        try {
            $memberName = $user->getName() . ' ' . $user->getLastname();
            NotificationService::sendToUsers(
                [$currentInstitutionOwner],
                'Clock In',
                $memberName . ' started a work session.'
            );
        } catch (Throwable $e) {
            error_log('[Level4 Clock] Clock-in notification failed: ' . $e->getMessage());
        }
        MessageUtil::setMessage("Work session started.");
        level4ClockRedirect(true);
    }

    if ($action === "end") {
        $activeLog = count($logs) > 0 ? $logs[0] : null;
        
        if (!$activeLog) {
            MessageUtil::setMessage("No active session found.");
            level4ClockRedirect();
        }

        try {
            $repo->stopNow(
                $activeLog->id,
                is_numeric($_POST["location_lat"] ?? null) ? (string)$_POST["location_lat"] : null,
                is_numeric($_POST["location_long"] ?? null) ? (string)$_POST["location_long"] : null,
                $_POST["notes"] ?? null
            );
        } catch (Throwable $e) {
            error_log('[Level4 Clock] Clock-out failed: ' . $e->getMessage());
            MessageUtil::setMessage('Unable to stop the clock. Please try again.', 'Error', 'error');
            level4ClockRedirect();
        }

        try {
            $memberName = $user->getName() . ' ' . $user->getLastname();
            NotificationService::sendToUsers(
                [$currentInstitutionOwner],
                'Clock Out',
                $memberName . ' ended a work session.'
            );
        } catch (Throwable $e) {
            error_log('[Level4 Clock] Clock-out notification failed: ' . $e->getMessage());
        }
        MessageUtil::setMessage("Work session ended.");
        level4ClockRedirect(true);
    }

    MessageUtil::setMessage("Invalid action.");
    level4ClockRedirect();
});

try {
    $router->run();
} catch (Throwable $e) {
    error_log('[Level4 Clock] Unhandled route failure: ' . $e->getMessage());
    MessageUtil::setMessage('Clock action failed. Please try again.', 'Error', 'error');
    LocationUtils::redirectInternal("panel/home");
}


