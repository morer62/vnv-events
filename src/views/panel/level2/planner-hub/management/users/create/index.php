<?php

use App\Entity\UserPermissions;
use App\Repositories\RolesRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserFeaturePermissionsRepository;
use App\Repositories\UserRolesRepository;
use App\Repositories\CrmCategoryRepository;
use App\Repositories\CrmLeadRepository;
use App\Services\HashService;
use App\Services\EmailService;
use App\Utils\FormatPhone;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Response;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Repositories\ClientsUsersRepository;
use App\Services\UserInstitutionService;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\UserInstitutionsRepository;

function sendTemporaryPasswordEmail(string $email, string $name, string $temporaryPassword, string $userType): bool
{
    try {
        $emailService = new EmailService();
        $baseUrl = ($_ENV["APP_URL"] ?? "https://vnvevents.com/");
        $loginUrl = $baseUrl . "/login";
        
        $userTypeText = ($userType === "4") ? "Team Member" : "Client";
        
        $subject = "Welcome to VNV Events - Your Temporary Password";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
                .content { background-color: #f9f9f9; padding: 30px; }
                .credentials { background-color: #fff; padding: 15px; border-left: 4px solid #4CAF50; margin: 20px 0; }
                .button { display: inline-block; padding: 12px 24px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                .warning { background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 Welcome to VNV Events!</h1>
                </div>
                <div class='content'>
                    <h2>Hello {$name},</h2>
                    <p>Your account has been created as a <strong>{$userTypeText}</strong>. Here are your login credentials:</p>
                    
                    <div class='credentials'>
                        <p><strong>📧 Email:</strong> {$email}</p>
                        <p><strong>🔑 Temporary Password:</strong> <code style='font-size: 16px; background: #f0f0f0; padding: 5px 10px; border-radius: 3px;'>{$temporaryPassword}</code></p>
                    </div>
                    
                    <div class='warning'>
                        <p><strong>⚠️ Important:</strong> This is a temporary password. We recommend changing it after your first login from your account settings.</p>
                    </div>
                    
                    <p>Click the button below to access the login page:</p>
                    <a href='{$loginUrl}' class='button'>🔐 Login Now</a>
                    
                    <p style='margin-top: 30px;'>If you have any questions, please don't hesitate to contact us.</p>
                    <p>Best regards,<br><strong>The VNV Events Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " VNV Events. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $emailService->sendSimpleEmail($email, $subject, $message, true);
    } catch (Exception $e) {
        return false;
    }
}

$router = new Router();

$router->get(function () {
    $rolesRepo = new RolesRepository();
    $categoryRepo = new CrmCategoryRepository();
    $candidate = $_SESSION["client_candidate"] ?? null;
    unset($_SESSION["client_candidate"]);

    $newClientForEstimate = $_SESSION["new_client_for_estimate"] ?? null;
    unset($_SESSION["new_client_for_estimate"]);

    $categories = $categoryRepo->getAllBy([
        ...LoginService::getUserIdAsArray(),
        ...LoginService::getOwnerAsArray()
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "allowPermissions" => UserPermissions::PERMISSIONS,
        "roles" => $rolesRepo->getAll(),
        "categories" => $categories,
        "candidate" => $candidate,
        "newClientForEstimate" => $newClientForEstimate,
        'base_url' => $_ENV["APP_URL"] ?? ''
    ]);
});

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_roles') {
    header('Content-Type: application/json');
    $rolesRepo = new \App\Repositories\RolesRepository();
    $roles = $rolesRepo->getAll();
    
    echo json_encode([
        "success" => true,
        "roles" => $roles
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && isset($_POST['user_type'])) {
    try {
        $userRepo = new UserRepository();
        $userInstitutionService = new UserInstitutionService();
        $institutionRepo = new InstitutionProfileRepository();
        $email = trim($_POST["email"] ?? "");
        $userType = $_POST["user_type"] ?? "";
        
        if (empty($email) || empty($userType)) {
            header('Content-Type: application/json');
            echo json_encode([
                "success" => false,
                "message" => "Email and user type are required"
            ]);
            exit;
        }
        
        // Validar formato de email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Content-Type: application/json');
            echo json_encode([
                "success" => false,
                "message" => "Please enter a valid email address"
            ]);
            exit;
        }
        
        $existing = $userRepo->getOneWithoutOwnership(["email" => $email, "is_active" => 1]);
        
        if ($existing) {
            if ((int)$existing->level === 5 && $userType === "5") {
                $assocRepo = new ClientsUsersRepository();
                $sessionUser = LoginService::getSession();
                $checkOwnerId = null;
                
                if ($sessionUser->getLevel() === 4) {
                    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
                    if ($currentInstitutionId) {
                        $currentInstitution = $institutionRepo->getById($currentInstitutionId);
                        $checkOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
                    }
                    
                    if (!$checkOwnerId) {
                        $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($sessionUser->getId());
                        if ($primaryInstitution) {
                            $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                            $checkOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
                        }
                    }
                } else {
                    $checkOwnerId = $sessionUser->getId();
                }
                
                $isAssociated = $assocRepo->getOne([
                    "client_id" => $existing->id,
                    "id_owner_asociated" => $checkOwnerId
                ]);
                
                if ($isAssociated) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        "success" => false,
                        "exists" => true,
                        "user_type" => "client",
                        "already_associated" => true,
                        "message" => "This client is already associated to your account."
                    ]);
                    exit;
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    "success" => false,
                    "exists" => true,
                    "user_type" => "client",
                    "message" => "Client already exists. You can associate this client to your account.",
                    "client_data" => [
                        "id" => $existing->id,
                        "name" => $existing->name,
                        "lastname" => $existing->lastname,
                        "email" => $existing->email,
                        "phone" => $existing->phone
                    ]
                ]);
                exit;
            } elseif ((int)$existing->level === 4 && $userType === "4") {
                $sessionUser = LoginService::getSession();
                $institutionOwnerId = null;
                
                if ($sessionUser->getLevel() === 4) {
                    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
                    if ($currentInstitutionId) {
                        $currentInstitution = $institutionRepo->getById($currentInstitutionId);
                        $institutionOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
                    }
                    
                    if (!$institutionOwnerId) {
                        $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($sessionUser->getId());
                        if ($primaryInstitution) {
                            $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                            $institutionOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
                        }
                    }
                } else {
                    $institutionOwnerId = $sessionUser->getIdOwner();
                }
                
                if ($institutionOwnerId) {
                    $currentInstitution = $institutionRepo->getByOwner($institutionOwnerId);
                    if ($currentInstitution && $userInstitutionService->userBelongsToInstitution($existing->id, $currentInstitution->id)) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            "success" => false,
                            "exists" => true,
                            "user_type" => "team_member",
                            "already_associated" => true,
                            "message" => "This team member is already associated with your institution."
                        ]);
                        exit;
                    }
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    "success" => false,
                    "exists" => true,
                    "user_type" => "team_member",
                    "message" => "Team member already exists. You can link this team member to your institution.",
                    "user_data" => [
                        "id" => $existing->id,
                        "name" => $existing->name,
                        "lastname" => $existing->lastname,
                        "email" => $existing->email,
                        "phone" => $existing->phone
                    ]
                ]);
                exit;
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    "success" => false,
                    "exists" => true,
                    "user_type" => "other",
                    "message" => "A user with this email already exists with a different user type."
                ]);
                exit;
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            "success" => true,
            "message" => "Email is available"
        ]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            "success" => false,
            "message" => "Error validating email: " . $e->getMessage()
        ]);
        exit;
    }
}

$router->post(function () {
    $userRepo = new UserRepository();
    $permissionsRepo = new UserFeaturePermissionsRepository();
    $roleRepo = new UserRolesRepository();
    $assocRepo = new ClientsUsersRepository();
    $leadRepo = new CrmLeadRepository();
    $sessionUser = LoginService::getSession();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();

    $currentOwnerId = null;
    $currentInstitutionId = null;
    
    if ($sessionUser->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            $currentOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
        }
        
        if (!$currentOwnerId) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($sessionUser->getId());
            if ($primaryInstitution) {
                $currentInstitution = $institutionRepo->getById($primaryInstitution->institution_id);
                $currentOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
                $currentInstitutionId = $primaryInstitution->institution_id;
            }
        }
    } else {
        $currentOwnerId = $sessionUser->getId();
        $currentInstitution = $institutionRepo->getByOwner($currentOwnerId);
        
        if (!$currentInstitution) {
            MessageUtil::setMessage("Error: You must create an institution profile before creating users. Please complete your institution profile first.");
            LocationUtils::redirectInternal("panel/planner-hub/institution-profile");
        }
        
        $currentInstitutionId = $currentInstitution->id;
    }

    // Validar formato de email antes de procesar
    $email = trim($_POST["email"] ?? "");
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        MessageUtil::setMessage("Error: Please enter a valid email address.");
        LocationUtils::reload();
    }
    
    $passIn = trim((string) ($_POST["password"] ?? ""));
    $passConfirmIn = trim((string) ($_POST["password_confirm"] ?? ""));
    $defaultPlain = "12345";

    if ($passIn === "" && $passConfirmIn === "") {
        $password = $defaultPlain;
        $passwordConfirm = $defaultPlain;
        $temporaryPassword = $password;
    } elseif ($passIn !== "" && $passConfirmIn !== "") {
        $password = $passIn;
        $passwordConfirm = $passConfirmIn;
        $temporaryPassword = $password;
    } else {
        MessageUtil::setMessage("Error: Enter both password and confirmation, or leave both empty to use the default (12345, digits 1–5).");
        LocationUtils::reload();
    }
    if (!empty($_POST["associate_client_id"])) {
        $clientId = (int)$_POST["associate_client_id"];
        $assocRepo->create($clientId, $currentOwnerId);
        MessageUtil::setMessage("Client associated successfully.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    if (!empty($_POST["link_existing_user_id"])) {
        $existingUserId = (int)$_POST["link_existing_user_id"];
        $linkRoleId = isset($_POST["link_role_id"]) && $_POST["link_role_id"] !== '' ? (int)$_POST["link_role_id"] : null;
        $linkHourlyRate = isset($_POST["link_hourly_rate"]) && $_POST["link_hourly_rate"] !== '' && is_numeric($_POST["link_hourly_rate"]) ? floatval($_POST["link_hourly_rate"]) : null;
        $linkContractDetail = isset($_POST["link_contract_detail"]) && !empty($_POST["link_contract_detail"]) ? $_POST["link_contract_detail"] : null;
        
        if (!$linkRoleId) {
            MessageUtil::setMessage("Error: Role is required when linking a team member.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users");
        }
        
        if (!$linkHourlyRate || $linkHourlyRate <= 0) {
            MessageUtil::setMessage("Error: Valid hourly rate is required when linking a team member.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users");
        }
        
        if (!$currentInstitutionId) {
            MessageUtil::setMessage("Error: No institution found for current user.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users");
        }
        
        $success = $userInstitutionService->linkExistingUserToInstitution($existingUserId, $currentInstitutionId, $linkRoleId, $linkHourlyRate, $linkContractDetail);
        
        if ($success) {
            if ($linkRoleId || $linkHourlyRate) {
                MessageUtil::setMessage("User linked to your institution successfully with role and hourly rate assigned.");
            } else {
                MessageUtil::setMessage("User linked to your institution successfully.");
            }
        } else {
            MessageUtil::setMessage("Error linking user to your institution.");
        }
        
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    if ($password !== $passwordConfirm) {
        return Response::createResponse("Passwords must match");
    }
    
    $hourlyRate = null;
    if ($_POST["level"] == 4 && isset($_POST["hourly_rate"]) && is_numeric($_POST["hourly_rate"])) {
        $hourlyRate = floatval($_POST["hourly_rate"]);
    }
    
    $inactiveUser = $userRepo->findInactiveByEmail($_POST["email"]);
    if ($inactiveUser) {
        if ((int)$inactiveUser->level === 4) {
            $roleId = isset($_POST["role_id"]) && $_POST["role_id"] !== '' ? (int) $_POST["role_id"] : null;
            $contractDetail = isset($_POST["contract_detail"]) && $_POST["contract_detail"] !== '' ? $_POST["contract_detail"] : null;

            $userRepo->reactivateUser($inactiveUser->id, $currentOwnerId, [
                "name" => $_POST["name"],
                "lastname" => $_POST["lastname"],
                "phone" => FormatPhone::formatPhone($_POST["phone"]),
                "password" => HashService::hashPassword($password),
                "password_updated" => 1,
                "hourly_rate" => $hourlyRate
            ]);

            if ($currentInstitutionId) {
                $userInstitutionsRepo = new UserInstitutionsRepository();
                $existingInstitutionRecord = $userInstitutionsRepo->getUserInstitutionRecord($inactiveUser->id, $currentInstitutionId);

                $institutionUpdateData = [];
                if ($roleId !== null) {
                    $institutionUpdateData["role_id"] = $roleId;
                }
                if ($hourlyRate !== null) {
                    $institutionUpdateData["hourly_rate"] = $hourlyRate;
                }
                if ($contractDetail !== null) {
                    $institutionUpdateData["contract_detail"] = $contractDetail;
                }

                $reactivated = false;
                if ($existingInstitutionRecord) {
                    $reactivated = $userInstitutionsRepo->reactivateUserInstitutionForInstitution(
                        (int) $inactiveUser->id,
                        (int) $currentInstitutionId,
                        $institutionUpdateData
                    );
                }

                if (!$reactivated) {
                    $userInstitutionService->addUserToInstitution(
                        (int) $inactiveUser->id,
                        (int) $currentInstitutionId,
                        $roleId,
                        $hourlyRate,
                        $contractDetail
                    );
                }
            }
            
            sendTemporaryPasswordEmail($_POST["email"], $_POST["name"], $temporaryPassword, $_POST["level"]);
            MessageUtil::setMessage("Team member reactivated and assigned to you.");
        } elseif ((int)$inactiveUser->level === 5) {
            $userRepo->reactivateUser($inactiveUser->id, 1, [
                "name" => $_POST["name"],
                "lastname" => $_POST["lastname"],
                "phone" => FormatPhone::formatPhone($_POST["phone"]),
                "password" => HashService::hashPassword($password),
                "password_updated" => 1
            ]);
            $assocRepo->create($inactiveUser->id, $currentOwnerId);
            
            sendTemporaryPasswordEmail($_POST["email"], $_POST["name"], $temporaryPassword, $_POST["level"]);
            MessageUtil::setMessage("Client reactivated and associated to your account.");
        }
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $existing = $userRepo->getOne(["email" => $_POST["email"], "is_active" => 1]);
    if ($existing) {
        if ((int)$existing->level === 5) {
            $_SESSION["client_candidate"] = [
                "name" => $existing->name,
                "lastname" => $existing->lastname,
                "email" => $existing->email,
                "phone" => $existing->phone,
                "client_id" => $existing->id
            ];
            MessageUtil::setMessage("Client already exists. You can associate it to your account.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/create");
        } else {
            $msg = ((int)$existing->level === 4)
                ? "Team member with this email already exists."
                : "A user with this email already exists.";
            MessageUtil::setMessage($msg);
            LocationUtils::reload();
        }
    }

    $ownerForInsert = ($_POST["level"] == 5) ? $currentOwnerId : $currentOwnerId;

    $userRepo->add([
        "name" => $_POST["name"],
        "lastname" => $_POST["lastname"],
        "email" => $_POST["email"],
        "password" => HashService::hashPassword($password),
        "password_updated" => 1,
        "phone" => FormatPhone::formatPhone($_POST["phone"]),
        "phone_validation" => 1,
        "phone_code" => '',
        "membership_due_date" => null,
        "level" => intval($_POST["level"]),
        "id_owner" => $ownerForInsert
    ]);

    $userId = $userRepo->getLastId();
    
    sendTemporaryPasswordEmail($_POST["email"], $_POST["name"], $temporaryPassword, $_POST["level"]);

    if (!$currentInstitutionId) {
        MessageUtil::setMessage("Error: No institution found for current user.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    if ($_POST["level"] == 4) {
        $roleId = isset($_POST["role_id"]) && !empty($_POST["role_id"]) ? (int) $_POST["role_id"] : null;
        $institutionHourlyRate = $hourlyRate;
        $contractDetail = isset($_POST["contract_detail"]) && !empty($_POST["contract_detail"]) ? $_POST["contract_detail"] : null;
        
        $userInstitutionService->addUserToInstitution($userId, $currentInstitutionId, $roleId, $institutionHourlyRate, $contractDetail);
    }

    if ($_POST["level"] == 5) {
        $assocRepo->create($userId, $currentOwnerId);
        
        if (isset($_POST["add_to_crm"]) && $_POST["add_to_crm"] === "1") {
            $address = $_POST["crm_address"] ?? "";
            $categoryId = $_POST["crm_category_id"] ?? null;
            
            if ($categoryId === "new") {
                $categoryName = $_POST["new_category_name"] ?? "New Category";
                $categoryRepo = new CrmCategoryRepository();
                
                $success = $categoryRepo->add([
                    "name" => $categoryName,
                    ...LoginService::getUserIdAsArray(true),
                    ...LoginService::getOwnerAsArray()
                ]);
                
                if ($success) {
                    $categoryId = $categoryRepo->getLastId();
                } else {
                    MessageUtil::setMessage("User created successfully. Note: CRM lead not created due to error creating category.");
                    LocationUtils::redirectInternal("panel/planner-hub/management/users");
                }
            }
            
            if ($categoryId && $categoryId !== "new") {
                $leadRepo->add([
                    "name" => $_POST["name"] . " " . $_POST["lastname"],
                    "email" => $_POST["email"],
                    "phone" => FormatPhone::formatPhone($_POST["phone"]),
                    "address" => $address,
                    "id_category" => $categoryId,
                    "id_status" => 1,
                    ...LoginService::getUserIdAsArray(true),
                    ...LoginService::getOwnerAsArray()
                ]);
                MessageUtil::setMessage("User created successfully and added to CRM.");
            } else {
                MessageUtil::setMessage("User created successfully. Note: CRM lead not created due to missing category.");
            }
        } else {
            MessageUtil::setMessage("User created successfully.");
        }
        $_SESSION["new_client_for_estimate"] = [
            "id" => $userId,
            "name" => $_POST["name"],
            "lastname" => $_POST["lastname"],
            "email" => $_POST["email"]
        ];
        LocationUtils::redirectInternal("panel/planner-hub/management/users/create");
    } elseif ($_POST["level"] == 4) {
        if (isset($_POST["role_id"]) && !empty($_POST["role_id"])) {
            MessageUtil::setMessage("Team member created successfully with role assigned.");
        } else {
            MessageUtil::setMessage("Team member created successfully.");
        }
    } else {
        MessageUtil::setMessage("User created successfully.");
    }

    LocationUtils::redirectInternal("panel/planner-hub/management/users");
});

$router->run();
