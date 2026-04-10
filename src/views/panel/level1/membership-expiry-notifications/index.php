<?php

use App\Repositories\UserRepository;
use App\Services\EmailService;
use App\Repositories\NotificationsRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;

$userRepo = new UserRepository();

// Procesar envío de notificaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_notifications') {
    $selectedUsers = $_POST['selected_users'] ?? [];
    $notificationType = $_POST['notification_type'] ?? 'expired';
    
    if (empty($selectedUsers)) {
        MessageUtil::setMessage("Please select at least one user to notify.");
        LocationUtils::reload();
    }
    
    $emailService = new EmailService();
    $notificationsRepo = new NotificationsRepository();
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($selectedUsers as $userId) {
        try {
            $user = $userRepo->getOne(["id" => $userId]);
            if (!$user || !$user->email) {
                $errorCount++;
                continue;
            }
            
            // Crear notificación en la base de datos
            $notificationMessage = $notificationType === 'expired' 
                ? "⚠️ Your Ophyra membership has expired. Please renew to continue using all features."
                : "⏰ Your Ophyra membership will expire soon. Please renew to avoid service interruption.";
            
            // Construir URL correcta basada en el nivel del usuario
            $appUrl = rtrim($_ENV["APP_URL"] ?? "http://localhost/vnv-venue", '/');
            $membershipUrl = $appUrl . "/panel/membership/manage";
            
            $notificationsRepo->add([
                "id_user" => $userId,
                "mensaje" => $notificationMessage,
                "link" => $membershipUrl,
                "leido" => 0
            ]);
            
            // Enviar email
            $subject = $notificationType === 'expired' 
                ? "⚠️ Membership Expired - Action Required"
                : "⏰ Membership Expiring Soon - Renew Now";
            
            $templateData = [
                'userName' => $user->name . ' ' . $user->lastname,
                'membershipType' => $notificationType,
                'expiryDate' => $user->membership_due_date ? date("F j, Y", strtotime($user->membership_due_date)) : 'N/A',
                'renewalUrl' => $membershipUrl,
                'isExpired' => $notificationType === 'expired'
            ];
            
            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/membership_expiry_notification.php");
            
            $emailResult = $emailService->sendTemplateEmail(
                $user->email,
                $subject,
                $templatePath,
                $templateData
            );
            
            if ($emailResult) {
                $successCount++;
                error_log("✅ Membership expiry notification sent successfully to: " . $user->email);
            } else {
                $errorCount++;
                error_log("❌ Failed to send membership expiry notification to: " . $user->email);
            }
            
        } catch (\Exception $e) {
            $errorCount++;
            error_log("Error sending membership expiry notification to user $userId: " . $e->getMessage());
        }
    }
    
    if ($successCount > 0) {
        MessageUtil::setMessage("Successfully sent notifications to $successCount user(s)." . ($errorCount > 0 ? " $errorCount failed." : ""));
    } else {
        MessageUtil::setMessage("Failed to send notifications. Please try again.");
    }
    
    LocationUtils::reload();
}

// Obtener parámetros de filtro y paginación
$searchTerm = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10; // Usuarios por página
$offset = ($page - 1) * $perPage;

// Obtener usuarios con membresías vencidas o próximas a vencer
$expiredUsers = $userRepo->getExpiredMembershipUsers($searchTerm, $perPage, $offset);
$expiringSoonUsers = $userRepo->getExpiringSoonMembershipUsers($searchTerm, $perPage, $offset);

// Obtener totales para paginación
$expiredTotal = $userRepo->getExpiredMembershipUsersCount($searchTerm);
$expiringSoonTotal = $userRepo->getExpiringSoonMembershipUsersCount($searchTerm);

// Calcular páginas totales
$expiredTotalPages = ceil($expiredTotal / $perPage);
$expiringSoonTotalPages = ceil($expiringSoonTotal / $perPage);

// Combinar y organizar por tipo de usuario
$allUsers = [
    'expired' => $expiredUsers,
    'expiring_soon' => $expiringSoonUsers
];

// Mostrar la vista
echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "users" => $allUsers,
    "expiredCount" => $expiredTotal,
    "expiringSoonCount" => $expiringSoonTotal,
    "searchTerm" => $searchTerm,
    "currentPage" => $page,
    "expiredTotalPages" => $expiredTotalPages,
    "expiringSoonTotalPages" => $expiringSoonTotalPages,
    "perPage" => $perPage
]);
