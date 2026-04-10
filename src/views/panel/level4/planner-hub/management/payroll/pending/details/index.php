<?php

use App\Repositories\PayrollHoursRepository;
use App\Repositories\PayrollPaymentsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\TimeService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\DateUtil;
use App\Utils\FileUtils;
use App\Utils\Router;
use App\Services\NotificationService;
use App\Repositories\UserCardsRepository;
use App\Repositories\StripeAccountsRepository;
use App\Services\StripeService;
use App\Utils\Response;
use App\Services\EmailService;
use App\Repositories\NotificationsRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Services\UserInstitutionService;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $repo = new PayrollHoursRepository();

    $id = $_GET["id"] ?? null;
    if (!$id) {
        MessageUtil::setMessage("Invalid user ID.");
        LocationUtils::redirectInternal("panel/planner-hub/management/payroll/pending");
    }

    $stripeRepo = new StripeAccountsRepository();
    $stripeAccount = $stripeRepo->getByUser($id);
    $isStripeVerified = $stripeAccount && $stripeAccount->is_verified == 1;

    $currentOwnerId = $user->getOwner();
    
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
            $currentOwnerId = $institutionOwnerId ?? $currentOwnerId;
        }
    }

    $hours = $repo->getUnpaidByUser($id, $currentOwnerId);
    $totalDiff = [];

    foreach ($hours as $h) {
        // Verificar que ambos campos de tiempo tengan valores válidos
        if ($h->start_time && $h->end_time) {
            $start = new DateTime($h->start_time);
            $end = new DateTime($h->end_time);

            if ($end > $start) {
                $h->total_hours = TimeService::getDateLocalDiff($h->start_time, $h->end_time);
                $totalDiff[] = TimeService::getDateDiff($h->start_time, $h->end_time);
                $h->can_pay = true; // Se puede pagar
            } else {
                $h->total_hours = "Invalid range";
                $h->can_pay = false; // No se puede pagar
            }
        } else {
            // Si falta start_time o end_time, marcar como sesión activa o incompleta
            if ($h->start_time && !$h->end_time) {
                $h->total_hours = "Active session";
                $h->can_pay = false; // No se puede pagar (sesión activa)
            } else {
                $h->total_hours = "Incomplete record";
                $h->can_pay = false; // No se puede pagar
            }
        }
        
        // Asegurar que siempre se muestre la hora inicial si existe
        if ($h->start_time) {
            $h->start_time_display = date('M j, g:i A', strtotime($h->start_time));
        } else {
            $h->start_time_display = "No start time";
        }
        
        // Mostrar hora final si existe
        if ($h->end_time) {
            $h->end_time_display = date('M j, g:i A', strtotime($h->end_time));
        } else {
            $h->end_time_display = "Session active";
        }
    }

    $intervalSum = TimeService::sumAllIntervals($totalDiff);
    $total = TimeService::getDateLocalDiffFromInterval($intervalSum);

    $userRepo = new UserRepository();
    $employee = $userRepo->getOne(["id" => $id]);
    
    $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
    $ownerInstitution = $institutionRepo->getByOwner($user->getIdOwner());
    $currentInstitutionId = $ownerInstitution ? $ownerInstitution->id : $user->getOwner();
    
    $userInstitutionsRepo = new UserInstitutionsRepository();
    $userInstitutionData = $userInstitutionsRepo->getUserInstitutionRecord($id, $currentInstitutionId);
    $hourlyRate = $userInstitutionData->hourly_rate ?? null;

    $totalSeconds = 0;
    foreach ($totalDiff as $interval) {
        $start = new DateTimeImmutable('@0');
        $end = $start->add($interval);
        $totalSeconds += $end->getTimestamp();
    }

    $totalHoursDecimal = round($totalSeconds / 3600, 2);
    $totalAmount = is_numeric($hourlyRate) ? $totalHoursDecimal * $hourlyRate : null;

    $cardsRepo = new UserCardsRepository();
    $cards = $cardsRepo->getByUserId($user->getId());
    $hasCard = count($cards) > 0;


    $stripeRepo = new StripeAccountsRepository();
    $employeeStripe = $stripeRepo->getByUser($_GET["id"]);

    $accountId = $employeeStripe->stripe_account_id;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "hours" => $hours,
        "userId" => $id,
        "total" => $total,
        "hourly_rate" => $hourlyRate,
        "total_amount" => $totalAmount,
        "hasCard" => $hasCard,
        "cards" => $cards,
        "isStripeVerified" => $isStripeVerified,
        "stripe_key" => $_ENV["STRIPE_PUBLIC"],
        'stripe_account_id' => $accountId,
        "employee" => $employee
    ]);
});

$router->post(function () {
$repoHours = new PayrollHoursRepository();
$repoPayments = new PayrollPaymentsRepository();
$userInstitutionService = new UserInstitutionService();
$user = LoginService::getSession();

    $action = $_POST["action"] ?? "";

$institutionRepo = new \App\Repositories\InstitutionProfileRepository();
$currentOwnerId = $user->getOwner();
$currentInstitutionId = null;

if ($user->getLevel() === 4) {
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    if ($currentInstitutionId) {
        $institution = $institutionRepo->getById($currentInstitutionId);
        if ($institution && isset($institution->id_owner)) {
            $currentOwnerId = $institution->id_owner;
        }
    } else {
        $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
        if ($primaryInstitution) {
            $currentInstitutionId = $primaryInstitution->institution_id;
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && isset($institution->id_owner)) {
                $currentOwnerId = $institution->id_owner;
            }
        }
    }
} else {
    $ownerInstitution = $institutionRepo->getByOwner($user->getIdOwner());
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
        MessageUtil::setMessage("Please provide start time, end time, and a valid user.");
        LocationUtils::reload();
    }

    $startTime = str_replace('T', ' ', $startInput) . ':00';
    $endTime = str_replace('T', ' ', $endInput) . ':00';

    if (strtotime($endTime) <= strtotime($startTime)) {
        MessageUtil::setMessage("End time must be later than start time.");
        LocationUtils::reload();
    }

    $created = $repoHours->createManualHour($userId, $currentOwnerId, $startTime, $endTime, $notes ?: null);

    if ($created) {
        MessageUtil::setMessage("Manual hours added successfully.");
    } else {
        MessageUtil::setMessage("Unable to add manual hours.", "Error", "error");
    }

    LocationUtils::redirectInternal("panel/planner-hub/management/payroll/pending/details?id=" . $userId);
}

if ($action === "deleteManualHour") {
    $userId = isset($_POST["user_id"]) ? (int)$_POST["user_id"] : 0;
    $hourId = isset($_POST["hour_id"]) ? (int)$_POST["hour_id"] : 0;

    if (!$userId || !$hourId) {
        MessageUtil::setMessage("Invalid request.");
        LocationUtils::reload();
    }

    $repoHours->deleteHourById($hourId);
    MessageUtil::setMessage("Manual hour removed successfully.");

    LocationUtils::redirectInternal("panel/planner-hub/management/payroll/pending/details?id=" . $userId);
}

    // Editar fecha
    if ($action === "editStatDate" || $action === "editEndDate") {
        $id = $_POST["id"] ?? null;
        $value = $_POST["date"] ?? "";
        $timezone = $_POST["userTimezone"] ?? "";

        if (!$id || !$value || !$timezone) {
            MessageUtil::setMessage("Invalid record ID or date.");
            LocationUtils::reload();
        }

        $field = $action === "editStatDate" ? "start_time" : "end_time";
        // Los datos ya están en hora local en la BD, solo agregar segundos
        $value = "$value:00";

        $repoHours->update([$field => $value], ["id" => $id]);
        MessageUtil::setMessage("Time updated successfully.");
        LocationUtils::reload();
    }

    // Editar notas
    if ($action === "editNotes") {
        $id = $_POST["id"] ?? null;
        $notes = $_POST["notes"] ?? "";

        if (!$id) {
            MessageUtil::setMessage("Invalid record ID.");
            LocationUtils::reload();
        }

        $repoHours->update(["notes" => $notes], ["id" => $id]);
        MessageUtil::setMessage("Notes updated successfully.");
        LocationUtils::reload();
    }

    $paymentType = $_POST["payment_type"] ?? "manual";
    $userId = $_POST["user_id"] ?? null;
    $ids = array_map('intval', $_POST["selected_ids"] ?? []);

    if (!$userId || empty($ids)) {
        MessageUtil::setMessage("Invalid input or no hours selected.");
        LocationUtils::reload();
    }

    // Verificar que solo se procesen sesiones completas (con start_time y end_time)
    $validHours = $repoHours->getUnpaidByUser($userId, $user->getOwner());
    $validIds = [];
    
    foreach ($validHours as $h) {
        if ($h->start_time && $h->end_time && in_array($h->id, $ids)) {
            $validIds[] = $h->id;
        }
    }
    
    if (empty($validIds)) {
        MessageUtil::setMessage("❌ No valid sessions selected for payment. Only completed sessions can be paid.");
        LocationUtils::reload();
    }
    
    // Usar solo los IDs válidos
    $ids = $validIds;

    $userRepo = new UserRepository();
    $employee = $userRepo->getOne(["id" => $userId]);
    
    $ownerInstitution = $institutionRepo->getByOwner($user->getIdOwner());
    $currentInstitutionId = $ownerInstitution ? $ownerInstitution->id : ($currentInstitutionId ?? $user->getOwner());
    
    $userInstitutionsRepo = new UserInstitutionsRepository();
    $userInstitutionData = $userInstitutionsRepo->getUserInstitutionRecord($userId, $currentInstitutionId);
    $hourlyRate = $userInstitutionData->hourly_rate ?? null;

    $totalDiff = [];
    $unpaidHours = $repoHours->getUnpaidByUser($userId, $user->getOwner());

    foreach ($unpaidHours as $h) {
        // Verificar que ambos campos de tiempo tengan valores válidos
        if ($h->start_time && $h->end_time) {
            $start = new DateTime($h->start_time);
            $end = new DateTime($h->end_time);
            if ($end > $start) {
                $totalDiff[] = TimeService::getDateDiff($h->start_time, $h->end_time);
            }
        }
    }

    $totalSeconds = 0;
    foreach ($totalDiff as $interval) {
        $start = new DateTimeImmutable('@0');
        $end = $start->add($interval);
        $totalSeconds += $end->getTimestamp();
    }

    $totalHoursDecimal = round($totalSeconds / 3600, 2); 
    $totalNet = is_numeric($hourlyRate) ? $totalHoursDecimal * $hourlyRate : null;
    $totalAmount = $totalNet ? round(($totalNet + 0.30) / (1 - 0.029), 2) : null;

    if ($paymentType === "card") {
       
        $stripeRepo = new StripeAccountsRepository();
        $stripe = new StripeService();

        $employeeStripe = $stripeRepo->getByUser($userId);
        $cardToken = $_POST["customer_token"] ?? null; 
        

        // Paso 1: Crear customer en cuenta conectada
        $customerId =  $stripe->createCustomerWithCardOnConnectedAccount($cardToken, $user->getEmail(), $user->getName(), $employeeStripe->stripe_account_id); 

         // Paso 2: Hacer cargo al customer

        $result =  $stripe->chargeCustomerOnConnectedAccount($customerId, $totalAmount, $employeeStripe->stripe_account_id);
 
        if (!$result) {
            MessageUtil::setMessage("❌ Stripe payment failed.");
            LocationUtils::reload();
        } 
    }

    // Procesar archivo comprobante si se envía
    $proofUrl = "";
    if (isset($_FILES["payment_proof_file"]) && $_FILES["payment_proof_file"]["error"] === 0) {
        $proofUrl = FileUtils::saveFile($_FILES["payment_proof_file"], "payroll");
    }

    $additionalInfo = $_POST['additional_info'] ?? 'No additional message';

    // Guardar pago en la base
    $save = $repoPayments->add([
        "id_user" => $userId,
        "id_owner" => $user->getOwner(),
        "hours_count" => count($ids),
        "hours_ids" => json_encode($ids),
        "proof_url" => $proofUrl,
        "paid_at" => date("Y-m-d H:i:s"),
        "hourly_rate_snapshot" => $hourlyRate,
        "aditional_info" => $additionalInfo
    ]); 

 
 
    // Crear notificación en la base de datos
    $notificationsRepo = new NotificationsRepository();
    $notificationsRepo->add([
        "id_user" => $userId,
        "mensaje" => "💵 Payment Received - Your work hours have been marked as paid. Total amount: $" . number_format($totalNet, 2) . " for " . count($ids) . " session(s).",
        "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/team/payroll/paid",
        "leido" => 0
    ]);

    // Enviar email de confirmación de pago
    try {
        $emailService = new EmailService();
        $subject = "💵 Payment Confirmed - Work Hours Payment";
        
        $templateData = [
            'employeeName' => $employee->name,
            'paymentAmount' => $totalNet,
            'sessionsCount' => count($ids),
            'paymentDate' => date("F j, Y g:i A"),
            'paymentMethod' => $paymentType === 'card' ? 'Credit Card' : 'Manual Payment',
            'additionalInfo' => $additionalInfo,
            'payrollUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/panel/planner-hub/team/payroll/paid"
        ];
        
        $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/payroll_payment_confirmation.php");
        
        $emailResult = $emailService->sendTemplateEmail(
            $employee->email,
            $subject,
            $templatePath,
            $templateData
        );
        
        if ($emailResult) {
            error_log("✅ Payroll payment confirmation email sent successfully to: " . $employee->email);
        } else {
            error_log("❌ Failed to send payroll payment confirmation email to: " . $employee->email);
        }
    } catch (\Exception $e) {
        error_log("Error sending payroll payment confirmation email: " . $e->getMessage());
    }

    // Enviar notificación push (mantener la funcionalidad existente)
    NotificationService::sendToUsers(
        [$userId],
        "💵 Payment Received",
        "Your work hours have been marked as paid. You can review the details in your Payroll Dashboard. Contact us if you notice anything wrong."
    );

    foreach ($ids as $id) {
        $repoHours->update([
            "is_paid" => 1,
            "paid_at" => date("Y-m-d H:i:s"),
            "proof_url" => $proofUrl
        ], ["id" => $id]);
    }

   MessageUtil::setMessage("Hours paid successfully.");
   LocationUtils::redirectInternal("panel/planner-hub/management/payroll/pending");
});

$router->run();


