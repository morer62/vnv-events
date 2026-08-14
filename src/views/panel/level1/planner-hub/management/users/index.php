<?php

use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Services\UserDeactivationService;
use App\Services\EmailService;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    if (!$user) {
        LocationUtils::redirectInternal("login");
    }

    $repo = new UserRepository();
    $clientsUsersRepo = new ClientsUsersRepository();
    $userInstitutionService = new UserInstitutionService();

    $filters = [];

    $activeTab = $_GET["active_tab"] ?? "members";
    
    $memberName = $_GET["member_name"] ?? null;
    $memberEmail = $_GET["member_email"] ?? null;
    $clientName = $_GET["client_name"] ?? null;
    $clientEmail = $_GET["client_email"] ?? null;

    if ($activeTab === "members") {
        if ($memberName) {
            $filters["name"] = $memberName;
        }
        if ($memberEmail) {
            $filters["email"] = $memberEmail;
        }
    } elseif ($activeTab === "clients") {
        if ($clientName) {
            $filters["name"] = $clientName;
        }
        if ($clientEmail) {
            $filters["email"] = $clientEmail;
        }
    }

    $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
    
    $institutionId = null;
    $currentOwnerId = null;
    
    if ($user->getLevel() === 4) {
        $institutionId = $_SESSION['current_institution_id'] ?? null;
        if ($institutionId) {
            $userInstitution = $institutionRepo->getById($institutionId);
            $currentOwnerId = $userInstitution ? $userInstitution->id_owner : null;
        }
        
        if (!$institutionId) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($user->getId());
            if ($primaryInstitution) {
                $institutionId = $primaryInstitution->institution_id;
                $userInstitution = $institutionRepo->getById($institutionId);
                $currentOwnerId = $userInstitution ? $userInstitution->id_owner : null;
            }
        }
    } else {
        $currentOwnerId = $user->getId();
        $userInstitution = $institutionRepo->getByOwner($currentOwnerId);
        $institutionId = $userInstitution ? $userInstitution->id : null;
    }
    
    $teamMembers = [];
    $clients = [];
    
    if ($institutionId && $currentOwnerId) {
        $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
        
        // Get team members from user_institutions (level 4 users linked to this institution)
        $memberFilters = $filters;
        $memberFilters["level"] = 4;
        $linkedTeamMembers = $userInstitutionsRepo->getUsersForInstitution($institutionId, $memberFilters);
        
        // Get team members directly owned by the institution owner
        $directTeamMembers = $repo->getAllFlexible(array_merge($filters, [
            "id_owner" => $currentOwnerId,
            "level" => 4
        ]));
        
        // Add user_institutions data to direct team members
        foreach ($directTeamMembers as $member) {
            $userInstitutionRecord = $userInstitutionsRepo->getUserInstitutionRecord($member->id, $institutionId);
            if ($userInstitutionRecord) {
                $member->institution_role_id = $userInstitutionRecord->role_id;
                $member->institution_hourly_rate = $userInstitutionRecord->hourly_rate;
                $member->role_name = $userInstitutionRecord->role_name ?? null;
                $member->institution_relationship = $userInstitutionRecord->secondary_institution_id ? 'secondary' : 'primary';
                $teamMembers[] = $member;
            }
        }

        // Merge and remove duplicates based on user ID
        $allTeamMembers = array_merge($linkedTeamMembers, $teamMembers);
        $teamMembersById = [];
        foreach ($allTeamMembers as $member) {
            $teamMembersById[$member->id] = $member;
        }
        $teamMembers = array_values($teamMembersById);

        $managerDb = new \App\Repositories\Connection();
        foreach ($teamMembers as $member) {
            $managerDb->query("SELECT COALESCE(p.is_event_manager,0) is_event_manager,(SELECT COUNT(*) FROM orders o WHERE o.id_owner=:owner AND o.main_manager_id=:manager AND o.event_date>=CURDATE() AND o.is_archived=0) future_manager_orders FROM users u LEFT JOIN event_manager_profiles p ON p.id_owner=:owner AND p.manager_id=u.id WHERE u.id=:manager");
            $managerDb->bind(':owner',(int)$currentOwnerId);$managerDb->bind(':manager',(int)$member->id);
            $managerMeta=$managerDb->fetchOne();
            $member->is_event_manager=(int)($managerMeta->is_event_manager??0);
            $member->future_manager_orders=(int)($managerMeta->future_manager_orders??0);
        }
        $managerDb->query("SELECT DISTINCT u.id,u.name,u.lastname,u.email,u.level FROM users u LEFT JOIN event_manager_profiles p ON p.id_owner=:owner AND p.manager_id=u.id WHERE u.is_active=1 AND ((u.id_owner=:owner AND u.level=4 AND p.is_event_manager=1) OR (u.level=1 AND (u.id=:owner OR LOWER(u.email)=LOWER(:email)))) ORDER BY u.level,u.name,u.lastname");
        $managerDb->bind(':owner',(int)$currentOwnerId);$managerDb->bind(':email',$_ENV['VNV_DEFAULT_MANAGER_EMAIL']??'info@vnvevents.com');
        $managerReplacements=$managerDb->fetchAll();

        $memberLatestContracts = [];
        if ($teamMembers) {
            $contractRepo = new \App\Repositories\TeamMemberContractsRepository();
            $memberLatestContracts = $contractRepo->getLatestByMembers(
                array_map(fn($member) => (int)$member->id, $teamMembers),
                (int)$currentOwnerId
            );
            foreach ($teamMembers as $member) {
                $member->team_contract = $memberLatestContracts[(int)$member->id] ?? null;
            }
        }
        
        $clients = $clientsUsersRepo->getClientsByOwner($currentOwnerId, $filters);
        
        $currentInstitution = $userInstitutionService->getCurrentInstitutionContext($user->getId());
    } else {
        $currentInstitution = null;
        $memberLatestContracts = [];
    }

    $availableInstitutions = $userInstitutionService->getUserAvailableInstitutions($user->getId());

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "team_members" => $teamMembers,
        "clients" => $clients,
        "user" => $user,
        "current_user" => $user,
        "active_tab" => $activeTab,
        "filter_member_name" => $memberName,
        "filter_member_email" => $memberEmail,
        "filter_client_name" => $clientName,
        "filter_client_email" => $clientEmail,
        "current_institution" => $currentInstitution,
        "available_institutions" => $availableInstitutions,
        "member_latest_contracts" => $memberLatestContracts,
        "manager_replacements" => $managerReplacements ?? []
    ]);
});

$router->post(function () {
    $repo = new UserRepository();
    $clientsUsersRepo = new ClientsUsersRepository();
    $userInstitutionService = new UserInstitutionService();
    $userDeactivationService = new UserDeactivationService();
    $user = LoginService::getSession();
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    // Level 1 normally works in its own institution without switching context.
    // Resolve that institution here as well, otherwise manager deactivation is
    // silently rejected with "No institution context selected".
    if ($user->getLevel() === 1) {
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $ownedInstitution = $institutionRepo->getByOwner($user->getId());
        $currentInstitutionId = $ownedInstitution ? (int)$ownedInstitution->id : null;
    }

    if (isset($_POST["switch_institution"])) {
        $institutionId = (int) $_POST["switch_institution"];
        $result = $userInstitutionService->switchInstitutionContext($user->getId(), $institutionId);
        
        if ($result['success']) {
            MessageUtil::setMessage("Institution context switched successfully.");
        } else {
            MessageUtil::setMessage("Error switching institution: " . $result['message']);
        }
        LocationUtils::reload();
    }

    if (isset($_POST["unlink_client"])) {
        $clientId = (int) $_POST["unlink_client"];
        $clientsUsersRepo->deleteRelation($clientId, $user->getIdOwner());
        MessageUtil::setMessage("Client unlinked successfully.");
        LocationUtils::reload();
    }

    if (isset($_POST["delete_client"])) {
        $clientId = (int) $_POST["delete_client"];
        $targetClient = $repo->getOne(["id" => $clientId]);
        
        if ($targetClient) {
            if ($targetClient->password_updated == 0) {
                $clientsUsersRepo->deleteRelation($clientId, $user->getIdOwner());
                $repo->delete_level_5(["id" => $clientId]);
                MessageUtil::setMessage("Client completely deleted successfully (unvalidated user).");
            } else {
                $clientsUsersRepo->deleteRelation($clientId, $user->getIdOwner());
                MessageUtil::setMessage("Client unlinked from your account.");
            }
        } else {
            MessageUtil::setMessage("Client not found.");
        }
        LocationUtils::reload();
    }

    if (isset($_POST["deactivate_user"])) {
        $userId = (int) $_POST["deactivate_user"];
        $reason = $_POST["deactivation_reason"] ?? null;
        $replacementManagerId = !empty($_POST['replacement_manager_id']) ? (int)$_POST['replacement_manager_id'] : null;
        
        if ($currentInstitutionId) {
            $result = $userDeactivationService->deactivateUserFromInstitution($userId, $currentInstitutionId, $user->getId(), $reason, $replacementManagerId);
            
            if ($result['success']) {
                MessageUtil::setMessage("User deactivated from institution successfully.");
            } else {
                MessageUtil::setMessage("Error deactivating user: " . $result['message']);
            }
        } else {
            MessageUtil::setMessage("No institution context selected.");
        }
        LocationUtils::reload();
    }

    if (isset($_POST["toggle_chat"])) {
        $targetId = (int) $_POST["toggle_chat"];
        $target = $repo->getOne(["id" => $targetId]);

        if ($target && $target->level == 4) {
            $newValue = $target->allow_chat_with_clients ? 0 : 1;
            $repo->update([
                "allow_chat_with_clients" => $newValue
            ], [
                "id" => $targetId
            ]);
            MessageUtil::setMessage("🔄Status updated.");
        }

        LocationUtils::reload();
    }

    if (isset($_POST["id"])) {
        $id = (int) $_POST["id"];
        if ($user->getLevel() === 4) {
            MessageUtil::setMessage("This action is only allowed for administrator users.");
            LocationUtils::reload();
        }
        
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $userInstitution = $institutionRepo->getByOwner($user->getIdOwner());
        $currentInstitutionId = $userInstitution ? $userInstitution->id : null;
        
        if ($currentInstitutionId) {
            $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
            
            $targetUser = $repo->getOne(["id" => $id]);
            
            if ($targetUser) {
                $userInstitutionRecord = $userInstitutionsRepo->getUserInstitutionRecord($id, $currentInstitutionId);

                if ((int) $targetUser->level === 4) {
                    if ($userInstitutionRecord) {
                        $userInstitutionsRepo->removeUserFromInstitution($id, $currentInstitutionId);
                        MessageUtil::setMessage("Team member unlinked from institution successfully.");
                    } else {
                        MessageUtil::setMessage("Team member not linked to this institution.");
                    }
                } elseif ((int) $targetUser->level === 5) {
                    if ($userInstitutionRecord) {
                        $userInstitutionsRepo->removeUserFromInstitution($id, $currentInstitutionId);
                    }
                    $repo->delete_level_5(["id" => $id]);
                    MessageUtil::setMessage("Client association removed successfully.");
                } else {
                    if ($userInstitutionRecord) {
                        $userInstitutionsRepo->removeUserFromInstitution($id, $currentInstitutionId);
                        MessageUtil::setMessage("User unlinked from institution successfully.");
                    } else {
                        MessageUtil::setMessage("User not found in current institution.");
                    }
                }
            } else {
                MessageUtil::setMessage("User not found.");
            }
        } else {
            MessageUtil::setMessage("No institution context found.");
        }
        
        LocationUtils::reload();
    }

    if (isset($_POST["resend_email"])) {
        $userId = (int) $_POST["resend_email"];
        $targetUser = $repo->getOne(["id" => $userId]);
        
        if ($targetUser) {
            $temporaryPassword = generateTemporaryPassword();
            $hashedPassword = \App\Services\HashService::hashPassword($temporaryPassword);
            
            $repo->update([
                "password" => $hashedPassword,
                "password_updated" => 1
            ], ["id" => $userId]);
            
            $subject = "Your Temporary Password - VNV Events";
            $message = "
                <h2>Welcome to VNV Events!</h2>
                <p>Hello {$targetUser->name},</p>
                <p>A new temporary password has been generated for your account:</p>
                <p><strong>Email:</strong> {$targetUser->email}</p>
                <p><strong>Temporary Password:</strong> {$temporaryPassword}</p>
                <p>Please log in using these credentials and set your permanent password.</p>
                <p><a href='" . \App\Utils\LocationUtils::getBasePath() . "/login' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Login Now</a></p>
                <p>Best regards,<br>VNV Events Team</p>
            ";
            
            $emailService = new EmailService();
            $emailService->sendSimpleEmail($targetUser->email, $subject, $message, true);
            
            MessageUtil::setMessage("Temporary password email sent successfully to {$targetUser->email}.");
        } else {
            MessageUtil::setMessage("User not found or already validated.");
        }
        
        LocationUtils::reload();
    }
});

function generateTemporaryPassword() {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < 12; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

$router->run();

