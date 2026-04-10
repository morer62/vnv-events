<?php

use App\Repositories\ClientsUsersRepository;
use App\Repositories\CrmCategoryRepository;
use App\Repositories\CrmLeadRepository;
use App\Repositories\RolesRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Services\LoginService;
use App\Services\UserInstitutionService;
use App\Services\UserEditService;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\FormatPhone;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $userRepo = new UserRepository();
    $roleRepo = new UserRolesRepository();
    $rolesRepo = new RolesRepository();
    $categoryRepo = new CrmCategoryRepository();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();

    $id = $_GET["id"] ?? null;
    if (!$id) {
        MessageUtil::setMessage("User ID not provided.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $session = LoginService::getSession();

    $currentInstitutionId = null;
    $currentOwnerId = null;
    $currentInstitution = null;
    
    if ($session->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            $currentOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
        }
        
        if (!$currentInstitutionId || !$currentInstitution) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($session->getId());
            if ($primaryInstitution) {
                $currentInstitutionId = $primaryInstitution->institution_id;
                $currentInstitution = $institutionRepo->getById($currentInstitutionId);
                $currentOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
            }
        }
    } else {
        $currentOwnerId = $session->getId();
        $currentInstitution = $institutionRepo->getByOwner($currentOwnerId);
        $currentInstitutionId = $currentInstitution ? $currentInstitution->id : null;
    }

    $user = $userRepo->getOneWithoutOwnership(["id" => $id]);

    if (!$user) {
        MessageUtil::setMessage("User not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $canAccess = false;
    
    if ($user->id_owner == $currentOwnerId) {
        $canAccess = true;
    } elseif ($currentInstitutionId && $userInstitutionService->userBelongsToInstitution($id, $currentInstitutionId)) {
        $canAccess = true;
    }

    if (!$canAccess) {
        MessageUtil::setMessage("You don't have permission to view this user.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $isPrimaryCompany = ($user->id_owner == $currentOwnerId);
    $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
    
    $role_id = null;
    $institution_hourly_rate = null;
    $contract_detail = null;
    
    if ($currentInstitution) {
        $userInstitutionRecord = $userInstitutionsRepo->getUserInstitutionRecord($user->id, $currentInstitution->id);
        if ($userInstitutionRecord) {
            $role_id = $userInstitutionRecord->role_id;
            $institution_hourly_rate = $userInstitutionRecord->hourly_rate;
            $contract_detail = $userInstitutionRecord->contract_detail ?? null;
        }
    }

    $categories = [];
    if ($currentOwnerId) {
        $categories = $categoryRepo->getAllByInstitutionOwner((int) $currentOwnerId);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user_edit" => $user,
        "roles" => $rolesRepo->getAll(),
        "selected_role_id" => $role_id,
        "institution_hourly_rate" => $institution_hourly_rate,
        "contract_detail" => $contract_detail,
        "is_primary_company" => $isPrimaryCompany,
        "crm_categories" => $categories
    ]);
});

$router->post(function () {
    $userRepo = new UserRepository();
    $roleRepo = new UserRolesRepository();
    $userInstitutionService = new UserInstitutionService();
    $institutionRepo = new InstitutionProfileRepository();
    $userEditService = new UserEditService();

    $id = $_POST["id"] ?? null;
    if (!$id) {
        MessageUtil::setMessage("User ID not provided.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $session = LoginService::getSession();

    // Determine current institution and owner based on session
    $currentInstitutionId = null;
    $currentOwnerId = null;
    $currentInstitution = null;
    
    if ($session->getLevel() === 4) {
        // For level 4 users, use the current institution from session
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $currentInstitution = $institutionRepo->getById($currentInstitutionId);
            $currentOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
        }
        
        // Fallback to primary institution
        if (!$currentInstitutionId || !$currentInstitution) {
            $primaryInstitution = $userInstitutionService->getUserPrimaryInstitution($session->getId());
            if ($primaryInstitution) {
                $currentInstitutionId = $primaryInstitution->institution_id;
                $currentInstitution = $institutionRepo->getById($currentInstitutionId);
                $currentOwnerId = $currentInstitution ? $currentInstitution->id_owner : null;
            }
        }
    } else {
        // For non-level 4 users, use their own ID as owner
        $currentOwnerId = $session->getId();
        $currentInstitution = $institutionRepo->getByOwner($currentOwnerId);
        $currentInstitutionId = $currentInstitution ? $currentInstitution->id : null;
    }

    $user = $userRepo->getOneWithoutOwnership(["id" => $id]);
    if (!$user) {
        MessageUtil::setMessage("User not found.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $canEdit = false;
    
    if ($user->id_owner == $currentOwnerId) {
        $canEdit = true;
    } else {
        if ($currentInstitutionId && $userInstitutionService->userBelongsToInstitution($id, $currentInstitutionId)) {
            $canEdit = true;
        }
    }

    if (!$canEdit) {
        MessageUtil::setMessage("You don't have permission to edit this user.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $isPrimaryCompany = ($user->id_owner == $currentOwnerId);

    $formType = $_POST["form_type"] ?? "update";

    if ($formType === "convert_to_client") {
        if (!$isPrimaryCompany) {
            MessageUtil::setMessage("Only the user's primary company can convert this member to a client.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        if ((int) $user->level !== 4) {
            MessageUtil::setMessage("Only team members can be converted to clients.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        if (!$currentOwnerId) {
            MessageUtil::setMessage("Unable to determine the institution owner for this operation.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        $clientsRepo = new ClientsUsersRepository();
        $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
        $categoryRepo = new CrmCategoryRepository();
        $leadRepo = new CrmLeadRepository();
        $sessionUser = LoginService::getSession();

        if ($currentInstitutionId) {
            $userInstitutionsRepo->removeUserFromInstitution((int) $user->id, (int) $currentInstitutionId);
            $userInstitutionsRepo->deleteUserInstitutionRelationship((int) $user->id, (int) $currentInstitutionId);
        }

        $userRepo->update([
            "level" => 5,
            "id_owner" => $currentOwnerId,
            "hourly_rate" => null
        ], ["id" => $id]);

        $clientsRepo->create((int) $user->id, (int) $currentOwnerId);

        $addToCrm = isset($_POST["add_to_crm"]) && $_POST["add_to_crm"] === "1";
        $existingLead = $leadRepo->getOneWithoutOwnership([
            "email" => $user->email ?? '',
            "id_owner" => $currentOwnerId
        ]);

        if ($addToCrm) {
            $categoryId = $_POST["crm_category_id"] ?? "";
            if ($categoryId === "new") {
                $newCategoryName = trim($_POST["new_category_name"] ?? "");
                if ($newCategoryName === "") {
                    MessageUtil::setMessage("Category name is required to create a new CRM category.");
                    LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
                }

                $categoryRepo->addWithExplicitOwner([
                    "name" => $newCategoryName,
                    "id_owner" => $currentOwnerId,
                    "id_user" => $sessionUser->getId()
                ]);
                $categoryId = (string) $categoryRepo->getLastId();
            }

            if (empty($categoryId)) {
                MessageUtil::setMessage("Please select a CRM category to continue.");
                LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
            }

            $address = $_POST["crm_address"] ?? "";

            if ($existingLead) {
                $leadRepo->update([
                    "name" => trim(($user->name ?? '') . ' ' . ($user->lastname ?? '')),
                    "phone" => FormatPhone::formatPhone($user->phone ?? ''),
                    "address" => $address,
                    "id_status" => 1,
                    "id_category" => (int) $categoryId,
                    "archived" => "NO"
                ], ["id" => $existingLead->id]);
            } else {
                $leadRepo->addWithExplicitOwner([
                    "id_user" => $sessionUser->getId(),
                    "name" => trim(($user->name ?? '') . ' ' . ($user->lastname ?? '')),
                    "email" => $user->email ?? '',
                    "phone" => FormatPhone::formatPhone($user->phone ?? ''),
                    "address" => $address,
                    "id_status" => 1,
                    "id_category" => (int) $categoryId,
                    "id_owner" => $currentOwnerId,
                    "archived" => "NO"
                ]);
            }
        } elseif ($existingLead) {
            $leadRepo->update(["archived" => "YES"], ["id" => $existingLead->id]);
        }

        MessageUtil::setMessage("Team member converted to client successfully.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    if ($formType === "convert_to_member") {
        if (!$isPrimaryCompany) {
            MessageUtil::setMessage("Only the user's primary company can convert this client to a team member.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        if ((int) $user->level !== 5) {
            MessageUtil::setMessage("Only clients can be converted to team members.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        if (!$currentInstitutionId || !$currentOwnerId) {
            MessageUtil::setMessage("Unable to determine the current institution context.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        $roleId = isset($_POST["convert_role_id"]) && $_POST["convert_role_id"] !== ''
            ? (int) $_POST["convert_role_id"]
            : null;

        if (!$roleId) {
            MessageUtil::setMessage("Role is required to convert this client to a team member.");
            LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
        }

        $hourlyRate = null;
        if (isset($_POST["convert_hourly_rate"]) && $_POST["convert_hourly_rate"] !== '') {
            if (!is_numeric($_POST["convert_hourly_rate"]) || floatval($_POST["convert_hourly_rate"]) < 0) {
                MessageUtil::setMessage("Please provide a valid hourly rate.");
                LocationUtils::redirectInternal("panel/planner-hub/management/users/edit/?id=" . $id);
            }
            $hourlyRate = floatval($_POST["convert_hourly_rate"]);
        }

        $contractDetail = $_POST["convert_contract_detail"] ?? null;

        $clientsRepo = new ClientsUsersRepository();
        $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
        $leadRepo = new CrmLeadRepository();

        $userRepo->update([
            "level" => 4,
            "id_owner" => $currentOwnerId,
            "hourly_rate" => $hourlyRate
        ], ["id" => $id]);

        $clientsRepo->deleteRelation((int) $user->id, (int) $currentOwnerId);

        $existingLead = $leadRepo->getOneWithoutOwnership([
            "email" => $user->email ?? '',
            "id_owner" => $currentOwnerId
        ]);
        if ($existingLead) {
            $leadRepo->delete(["id" => $existingLead->id]);
        }

        $userInstitutionService->addUserToInstitution(
            (int) $user->id,
            (int) $currentInstitutionId,
            $roleId,
            $hourlyRate,
            $contractDetail
        );

        // Reactivate or update any existing inactive relationship
        $userInstitutionsRepo->reactivateUserInstitutionForInstitution(
            (int) $user->id,
            (int) $currentInstitutionId,
            [
                "role_id" => $roleId,
                "hourly_rate" => $hourlyRate,
                "contract_detail" => $contractDetail
            ]
        );

        MessageUtil::setMessage("Client converted to team member successfully.");
        LocationUtils::redirectInternal("panel/planner-hub/management/users");
    }

    $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
    
    $userInstitutionRecord = null;
    $current_role_id = null;
    $current_hourly_rate = null;
    
    if ($currentInstitution) {
        $userInstitutionRecord = $userInstitutionsRepo->getUserInstitutionRecord($user->id, $currentInstitution->id);
        if ($userInstitutionRecord) {
            $current_role_id = $userInstitutionRecord->role_id;
            $current_hourly_rate = $userInstitutionRecord->hourly_rate;
        }
    }

    $originalData = [
        "name" => $user->name,
        "lastname" => $user->lastname,
        "email" => $user->email,
        "phone" => $user->phone,
        "level" => $user->level,
        "role_id" => $current_role_id,
        "hourly_rate" => $current_hourly_rate
    ];

    $newData = [];

    if ($user->level == 5 && $user->password_updated == 1) {
        // Clients validated: Only allow editing name and phone
        if (isset($_POST["name"]) && !empty($_POST["name"])) {
            $newData["name"] = $_POST["name"];
        }
        if (isset($_POST["lastname"]) && !empty($_POST["lastname"])) {
            $newData["lastname"] = $_POST["lastname"];
        }
        if (isset($_POST["phone"])) {
            $newData["phone"] = $_POST["phone"];
        }
    } elseif ($user->password_updated == 0) {
        // Users not yet validated (clients or team members): Allow editing all fields
        if (isset($_POST["name"]) && !empty($_POST["name"])) {
            $newData["name"] = $_POST["name"];
        }
        if (isset($_POST["lastname"]) && !empty($_POST["lastname"])) {
            $newData["lastname"] = $_POST["lastname"];
        }
        if (isset($_POST["email"]) && !empty($_POST["email"])) {
            $newData["email"] = $_POST["email"];
        }
        if (isset($_POST["phone"])) {
            $newData["phone"] = $_POST["phone"];
        }
    }

    $institutionUpdateData = [];
    
    if ($user->level == 4 && isset($_POST["hourly_rate"]) && is_numeric($_POST["hourly_rate"])) {
        $institutionUpdateData["hourly_rate"] = floatval($_POST["hourly_rate"]);
        $newData["hourly_rate"] = floatval($_POST["hourly_rate"]);
    }

    if ($user->level == 4 && isset($_POST["role_id"]) && $_POST["role_id"] !== '') {
        $institutionUpdateData["role_id"] = (int) $_POST["role_id"];
        $newData["role_id"] = (int) $_POST["role_id"];
    }

    if ($user->level == 4 && isset($_POST["contract_detail"])) {
        $institutionUpdateData["contract_detail"] = $_POST["contract_detail"];
        $newData["contract_detail"] = $_POST["contract_detail"];
    }

    // Update user table (excluding role_id, hourly_rate and contract_detail which go to user_institutions)
    $userUpdateData = $newData;
    unset($userUpdateData["role_id"]);
    unset($userUpdateData["hourly_rate"]);
    unset($userUpdateData["contract_detail"]);
    
    if (!empty($userUpdateData)) {
        $userRepo->update($userUpdateData, ["id" => $id]);
    }

    // Update user_institutions table
    if (!empty($institutionUpdateData) && $userInstitutionRecord) {
        $userInstitutionsRepo->updateUserInstitutionData($userInstitutionRecord->id, $institutionUpdateData);
    }

    if (!empty($newData)) {
        if ($user->password_updated == 0) {
            MessageUtil::setMessage("User updated successfully.");
        } elseif (!$isPrimaryCompany) {
            $userEditService->logUserEdit($id, $session->getId(), $originalData, $newData);
            MessageUtil::setMessage("User updated successfully. The user's owner has been notified of the changes.");
        } else {
            MessageUtil::setMessage("User updated successfully.");
        }
    } else {
        MessageUtil::setMessage("No changes were made.");
    }
    
    LocationUtils::redirectInternal("panel/planner-hub/management/users");
});

$router->run();
