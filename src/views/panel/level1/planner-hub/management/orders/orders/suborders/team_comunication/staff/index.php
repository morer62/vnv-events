<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersSuborderStaffInvitesRepository;
use App\Repositories\UserInstitutionsRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\NotificationsRepository;
use App\Services\EmailService;
use App\Services\NotificationService;

$router = new Router();

function suborders_list_path(): string {
    return 'panel/planner-hub/management/orders/orders/suborders/';
}

$router->get(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $invitesRepo = new OrdersSuborderStaffInvitesRepository();
    $userInstitutionsRepo = new UserInstitutionsRepository();

    $suborderId = (int)($_GET['id'] ?? 0);
    if ($suborderId <= 0) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if (!$currentInstitutionId) {
            die("❌ No institution selected.");
        }

        $institutionRepo = new InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        if (!$institution) {
            die("❌ Institution not found.");
        }

        $institutionOwnerId = $institution->id_owner;
        $order = $orderRepo->getOneByIdAndOwner($suborder->id_order, $institutionOwnerId);
        $institutionId = $currentInstitutionId;
    } else {
        $order = $orderRepo->getOne([
            'id' => $suborder->id_order,
            'id_owner' => $user->getOwner(),
        ]);

        $institutionRepo = new InstitutionProfileRepository();
        $userInstitution = $institutionRepo->getByOwner($user->getIdOwner());
        $institutionId = $userInstitution ? $userInstitution->id : null;
    }

    if (!$order) {
        die("❌ Order not found or not accessible.");
    }
    
    if ($institutionId) {
        $allStaff = $userInstitutionsRepo->getUsersForInstitution($institutionId, ["level" => 4]);
    } else {
        $allStaff = [];
    }

    $invited = $invitesRepo->getInvitesBySuborder($suborderId);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'id' => $suborderId,
        'suborder' => $suborder,
        'order' => $order,
        'staff' => $allStaff,
        'invited' => $invited,
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $invitesRepo = new OrdersSuborderStaffInvitesRepository();

    $suborderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $staffId = (int)($_POST['id_user'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($suborderId <= 0) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::reload();
    }

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if (!$currentInstitutionId) {
            die("❌ No institution selected.");
        }

        $institutionRepo = new InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        if (!$institution) {
            die("❌ Institution not found.");
        }

        $institutionOwnerId = $institution->id_owner;
        $order = $orderRepo->getOneByIdAndOwner($suborder->id_order, $institutionOwnerId);
    } else {
        $order = $orderRepo->getOne([
            'id' => $suborder->id_order,
            'id_owner' => $user->getOwner(),
        ]);
    }

    if (!$order) {
        die("❌ Order not found or not accessible.");
    }

    if ($staffId && $action === "invite") {
        $existingInvite = $invitesRepo->getInvite($suborderId, $staffId);
        if (!$existingInvite) {
            $invitesRepo->inviteUser($suborderId, $staffId);
            
            try {
                $userRepo = new UserRepository();
                $staffMember = $userRepo->getOneWithoutOwnership(['id' => $staffId]);
                
                if ($staffMember && $staffMember->email) {
                    $emailService = new EmailService();
                    $subject = "👥 Staff Invitation - Sub-Order #{$suborderId} for Order #VNV341{$order->id}";
                    
                    $templateData = [
                        'staff_name' => $staffMember->name . ' ' . $staffMember->lastname,
                        'event_date' => date("l, F j, Y", strtotime($order->event_date)),
                        'start_time' => date("g:i A", strtotime($order->start_time)),
                        'end_time' => date("g:i A", strtotime($order->end_time)),
                        'address' => $order->address,
                        'invitation_url' => ($_ENV["APP_URL"] ?? "https://ophyra.com") . "/panel/planner-hub/team/orders/orders",
                        'app_url' => $_ENV["APP_URL"] ?? "https://ophyra.com"
                    ];
                    
                    $projectRoot = dirname(__DIR__, 8);
                    $templatePath = $projectRoot . "/src/templates/emails/staff-invitation-suborder.html";
                    
                    if (file_exists($templatePath)) {
                        $emailService->sendTemplateEmail($staffMember->email, $subject, $templatePath, $templateData);
                    } else {
                        $simpleEmailBody = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                            <h2 style='color: #333;'>👥 Staff Invitation</h2>
                            <p>Hello <strong>{$staffMember->name} {$staffMember->lastname}</strong>,</p>
                            
                            <p>You have been invited to work on a sub-order for an upcoming event.</p>
                            
                            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                <h3 style='color: #495057; margin-top: 0;'>📋 Event Details</h3>
                                <p><strong>Event Date:</strong> " . date("l, F j, Y", strtotime($order->event_date)) . "</p>
                                <p><strong>Time:</strong> " . date("g:i A", strtotime($order->start_time)) . " - " . date("g:i A", strtotime($order->end_time)) . "</p>
                                <p><strong>Address:</strong> {$order->address}</p>
                            </div>
                            
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='" . ($_ENV["APP_URL"] ?? "https://ophyra.com") . "/panel/planner-hub/team/orders/orders' 
                                   style='background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>
                                    View Invitation
                                </a>
                            </div>
                        </div>";
                        
                        $emailService->sendSimpleEmail($staffMember->email, $subject, $simpleEmailBody, true);
                    }
                    
                    $notificationsRepo = new NotificationsRepository();
                    $notificationMessage = "👥 New Staff Invitation - Sub-Order #{$suborderId} for Order #VNV341{$order->id} on " . date("M j, Y", strtotime($order->event_date));
                    $notificationUrl = ($_ENV["APP_URL"] ?? "https://ophyra.com") . "/panel/planner-hub/team/orders/orders";
                    
                    $notificationsRepo->add([
                        "id_user" => $staffId,
                        "mensaje" => $notificationMessage,
                        "link" => $notificationUrl,
                        "leido" => 0
                    ]);
                    
                    if ($staffMember->expo_token) {
                        NotificationService::sendExpoNotification(
                            $staffMember->expo_token,
                            "👥 New Staff Invitation",
                            "You've been invited to work on Sub-Order #{$suborderId} for " . date("M j, Y", strtotime($order->event_date))
                        );
                    }
                }
            } catch (Exception $e) {
                error_log("Error sending staff invitation notification: " . $e->getMessage());
            }
            
            MessageUtil::setMessage('✅ Staff invitation sent successfully with email and notification.');
        } else {
            MessageUtil::setMessage('⚠️ Staff member already invited.');
        }
    }

    if ($staffId && $action === "remove") {
        $invitesRepo->removeInvite($suborderId, $staffId);
        MessageUtil::setMessage('✅ Staff invitation removed.');
    }

    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
