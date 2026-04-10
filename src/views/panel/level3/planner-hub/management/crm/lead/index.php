<?php

use App\Entity\User;
use App\Repositories\CrmLeadRepository;
use App\Repositories\CrmCategoryRepository;
use App\Repositories\CrmStatusRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;
use App\Utils\Router;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();

    $user         = LoginService::getSession();
    $leadRepo     = new CrmLeadRepository();
    $categoryRepo = new CrmCategoryRepository();
    $statusRepo   = new CrmStatusRepository();
    $userRepo     = new UserRepository();

    $institutionOwnerId = null;
    $currentInstitutionId = null;
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            $institutionOwnerId = $institution ? $institution->id_owner : null;
        }
    }

    if (isset($_GET["export_template"]) && $_GET["export_template"] === "1") {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=crm_leads_template.csv");
        $output = fopen("php://output", "w");
        
        fputcsv($output, ["Name", "Email", "Phone", "Address", "Status", "Category", "Language", "Comments"]);
        
        fclose($output);
        exit;
    }

    if (isset($_GET["export"]) && $_GET["export"] === "1") {
        if ($user->getLevel() === 4 && $institutionOwnerId) {
            $leads = $leadRepo->getAllByInstitutionOwner($institutionOwnerId);
            
            if (!empty($_GET["archived"])) {
                $leads = array_filter($leads, fn($lead) => $lead->archived === $_GET["archived"]);
            }
        } else {
            $exportFilters = [
                ...(in_array($user->getLevel(), User::EXTERNAL_USER_LEVEL) ? LoginService::getUserIdAsArray(true) : []),
                ...LoginService::getOwnerAsArray()
            ];

            if (!empty($_GET["name"]))  $exportFilters["name"] = $_GET["name"];
            if (!empty($_GET["email"])) $exportFilters["email"] = $_GET["email"];
            if (!empty($_GET["status"]) && $_GET["status"] !== "all") {
                $exportFilters["id_status"] = (int)$_GET["status"];
            }
            
            $exportArchived = $_GET["archived"] ?? "NO";
            if ($exportArchived === "YES" || $exportArchived === "NO") {
                $exportFilters["archived"] = $exportArchived;
            }

            $leads = $leadRepo->getAllBy($exportFilters);
        }

        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=crm_leads_export.csv");
        $output = fopen("php://output", "w");

        $statuses = $statusRepo->getAll();
        $statusIndexed = [];
        foreach ($statuses as $status) {
            $statusIndexed[$status->id] = $status->name;
        }

        $categories = $categoryRepo->getAllIndexedById();

        fputcsv($output, ["Name", "Email", "Phone", "Address", "Status", "Category", "Language", "Comments"]);

        foreach ($leads as $lead) {
            $categoryName = $categories[$lead->id_category]->name ?? 'N/A';
            $statusName   = $statusIndexed[$lead->id_status] ?? 'N/A';

            fputcsv($output, [
                $lead->name,
                $lead->email,
                $lead->phone,
                $lead->address,
                $statusName,
                $categoryName,
                $lead->languaje ?? 'english',
                $lead->comments ?? ''
            ]);
        }

        fclose($output);
        exit;
    }

    $searchName   = $_GET["name"]  ?? null;
    $searchEmail  = $_GET["email"] ?? null;
    $filterStatus = $_GET["status"] ?? null;
    $filterArchived = $_GET["archived"] ?? "NO";

    if ($user->getLevel() === 4 && $institutionOwnerId) {
        $userLeadFilters = [
            "id_user" => $user->getId(),
            "id_owner" => $institutionOwnerId,
            "archived" => "NO"
        ];
        $activeLeads = $leadRepo->paginateAndFilter($userLeadFilters, 1, 100);
        
        $userArchivedFilters = [
            "id_user" => $user->getId(),
            "id_owner" => $institutionOwnerId,
            "archived" => "YES"
        ];
        $archivedLeads = $leadRepo->paginateAndFilter($userArchivedFilters, 1, 100);
        
        $displayFilters = [
            "id_user" => $user->getId(),
            "id_owner" => $institutionOwnerId,
            "archived" => $filterArchived
        ];
        if ($searchName)  $displayFilters["name"]      = $searchName;
        if ($searchEmail) $displayFilters["email"]     = $searchEmail;
        if ($filterStatus && $filterStatus !== "all") $displayFilters["id_status"] = (int)$filterStatus;
        
        $leadsToShow = $leadRepo->paginateAndFilter($displayFilters, 1, 100);
        $categories = $categoryRepo->getAllByInstitutionOwner($institutionOwnerId);
        $teamUsers = [];
    } else {
        $activeFilters = [
            ...(in_array($user->getLevel(), User::EXTERNAL_USER_LEVEL) ? LoginService::getUserIdAsArray(true) : []),
            ...LoginService::getOwnerAsArray(),
            "archived" => "NO"
        ];
        $activeLeads = $leadRepo->paginateAndFilter($activeFilters, 1, 100);
        
        $archivedFilters = [
            ...(in_array($user->getLevel(), User::EXTERNAL_USER_LEVEL) ? LoginService::getUserIdAsArray(true) : []),
            ...LoginService::getOwnerAsArray(),
            "archived" => "YES"
        ];
        $archivedLeads = $leadRepo->paginateAndFilter($archivedFilters, 1, 100);
        
        $displayFilters = [
            ...(in_array($user->getLevel(), User::EXTERNAL_USER_LEVEL) ? LoginService::getUserIdAsArray(true) : []),
            ...LoginService::getOwnerAsArray(),
            "archived" => $filterArchived
        ];
        
        if ($searchName)  $displayFilters["name"]      = $searchName;
        if ($searchEmail) $displayFilters["email"]     = $searchEmail;
        if ($filterStatus && $filterStatus !== "all") $displayFilters["id_status"] = (int)$filterStatus;
        
        $leadsToShow = $leadRepo->paginateAndFilter($displayFilters, 1, 100);
        $categories = $categoryRepo->getAllBy(["id_user" => $user->getId()]);
        
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $userInstitution = $institutionRepo->getByOwner($user->getId());
        $teamUsers = [];
        
        if ($userInstitution) {
            $userInstitutionsRepo = new \App\Repositories\UserInstitutionsRepository();
            $institutionUsers = $userInstitutionsRepo->getUsersForInstitution($userInstitution->id);
            
            $teamUserIds = array_unique(array_map(fn($u) => $u->id, $institutionUsers));
            foreach ($teamUserIds as $userId) {
                $teamUser = $userRepo->getOne(["id" => $userId]);
                if ($teamUser) {
                    $teamUsers[] = $teamUser;
                }
            }
        } else {
            $teamUsers = $userRepo->getAllBy(["level" => 4, "id_owner" => $user->getId()]);
        }
    }

    $statuses   = $statusRepo->getAll();

    $allUsersIndexed = [];
    foreach ($teamUsers as $u) {
        $allUsersIndexed[$u->id] = $u;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "leads"         => $leadsToShow["data"],
        "active_leads_count" => $activeLeads["total"],
        "archived_leads_count" => $archivedLeads["total"],
        "categories"    => $categories,
        "statuses"      => $statuses,
        "team_users"    => $teamUsers,
        "users"         => $allUsersIndexed,
        "filter_name"   => $searchName,
        "filter_email"  => $searchEmail,
        "filter_status" => $filterStatus,
        "filter_archived" => $filterArchived,
        "session_level" => $user->getLevel(),
        "session_user"  => $user,
        "session_message" => $_SESSION['message'] ?? null
    ]);
    
    if (isset($_SESSION['message'])) {
        unset($_SESSION['message']);
    }
});

$router->post(function () {
    $context = UserContext::get();
    $repo    = new CrmLeadRepository();
    $user    = LoginService::getSession();

    if (($_POST["action"] ?? null) === "assign_user") {
        $repo->update(["id_user" => (int)$_POST["user_id"]], ["id" => (int)$_POST["lead_id"]]);
        MessageUtil::setMessage("Lead reassigned successfully.");
        LocationUtils::reload();
    }

    if (($_POST["action"] ?? null) === "archive_lead") {
        $id = (int)($_POST["id"] ?? 0);
        if (!$id) {
            MessageUtil::setMessage("Lead not found.");
            LocationUtils::reload();
        }

        if ($user->getLevel() > 3) {
            MessageUtil::setMessage("You don't have permission to archive leads.");
            LocationUtils::reload();
        }

        $repo->update(["archived" => "YES"], ["id" => $id]);
        MessageUtil::setMessage("Lead archived successfully.");
        LocationUtils::reload();
    }

    if (($_POST["action"] ?? null) === "unarchive_lead") {
        $id = (int)($_POST["id"] ?? 0);
        if (!$id) {
            MessageUtil::setMessage("Lead not found.");
            LocationUtils::reload();
        }

        if ($user->getLevel() > 3) {
            MessageUtil::setMessage("You don't have permission to unarchive leads.");
            LocationUtils::reload();
        }

        $repo->update(["archived" => "NO"], ["id" => $id]);
        MessageUtil::setMessage("Lead unarchived successfully.");
        LocationUtils::reload();
    }

    if (($_POST["action"] ?? null) === "delete_archived_lead") {
        $id = (int)($_POST["id"] ?? 0);
        if (!$id) {
            MessageUtil::setMessage("Lead not found.");
            LocationUtils::reload();
        }

        if ($user->getLevel() > 3) {
            MessageUtil::setMessage("You don't have permission to delete archived leads.");
            LocationUtils::reload();
        }

        $lead = $repo->getOne(["id" => $id]);
        if (!$lead || $lead->archived !== "YES") {
            MessageUtil::setMessage("Only archived leads can be permanently deleted.");
            LocationUtils::reload();
        }

        $repo->delete(["id" => $id]);
        MessageUtil::setMessage("Archived lead deleted permanently.");
        LocationUtils::reload();
    }
    
    if (($_POST["action"] ?? null) === "bulk_archive") {
        if ($user->getLevel() > 3) {
            MessageUtil::setMessage("You don't have permission to archive leads.");
            LocationUtils::reload();
        }
        
        $leadIds = $_POST["lead_ids"] ?? [];
        if (empty($leadIds)) {
            MessageUtil::setMessage("No leads selected.");
            LocationUtils::reload();
        }
        
        $count = 0;
        foreach ($leadIds as $leadId) {
            $id = (int)$leadId;
            if ($id > 0) {
                $repo->update(["archived" => "YES"], ["id" => $id]);
                $count++;
            }
        }
        
        MessageUtil::setMessage($count . " lead(s) archived successfully.");
        LocationUtils::reload();
    }
    
    if (($_POST["action"] ?? null) === "bulk_delete_archived") {
        if ($user->getLevel() > 3) {
            MessageUtil::setMessage("You don't have permission to delete archived leads.");
            LocationUtils::reload();
        }
        
        $leadIds = $_POST["lead_ids"] ?? [];
        if (empty($leadIds)) {
            MessageUtil::setMessage("No leads selected.");
            LocationUtils::reload();
        }
        
        $count = 0;
        foreach ($leadIds as $leadId) {
            $id = (int)$leadId;
            if ($id > 0) {
                $lead = $repo->getOne(["id" => $id]);
                if ($lead && $lead->archived === "YES") {
                    $repo->delete(["id" => $id]);
                    $count++;
                }
            }
        }
        
        MessageUtil::setMessage($count . " archived lead(s) deleted permanently.");
        LocationUtils::reload();
    }

            if (isset($_FILES["csv_file"]) && $_FILES["csv_file"]["tmp_name"]) {
            $file = $_FILES["csv_file"]["tmp_name"];
            $handle = fopen($file, "r");

            $header = fgetcsv($handle, 1000, ",");
            $errors = [];
            $rows   = [];

        $categories = (new CrmCategoryRepository())->getAll();
        $statuses   = (new CrmStatusRepository())->getAll();

        $catIndex = [];
        foreach ($categories as $c) $catIndex[strtolower(trim($c->name))] = $c->id;

        $statusIndex = [];
        foreach ($statuses as $s) $statusIndex[strtolower(trim($s->name))] = $s->id;

        $rowNumber = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            $rowNumber++;
            $fieldCount = count($data);
            
            if ($fieldCount < 6) {
                $errors[] = "Row $rowNumber: Invalid format - expected at least 6 fields, got $fieldCount";
                continue;
            }
            
            [$name, $email, $phone, $address, $statusName, $categoryName] = $data;
            
            // Validar campos obligatorios
            $rowErrors = [];
            
            if (empty(trim($name))) {
                $rowErrors[] = "Name is empty";
            }
            if (empty(trim($email))) {
                $rowErrors[] = "Email is empty";
            }
            if (empty(trim($phone))) {
                $rowErrors[] = "Phone is empty";
            }
            if (empty(trim($address))) {
                $rowErrors[] = "Address is empty";
            }
            
            if (!empty($rowErrors)) {
                $errors[] = "Row $rowNumber: " . implode(", ", $rowErrors);
                continue;
            }
            
            $language = $fieldCount > 6 ? trim($data[6] ?? 'english') : 'english';
            $comments = $fieldCount > 7 ? trim($data[7] ?? '') : '';

            if (empty(trim($statusName))) {
                $statusName = "To Be Contacted";
            }
            
            if (empty(trim($categoryName))) {
                $categoryName = "General";
            }

            $normalizedStatus   = strtolower(trim($statusName));
            $normalizedCategory = strtolower(trim($categoryName));

            $id_status   = $statusIndex[$normalizedStatus] ?? null;
            $id_category = $catIndex[$normalizedCategory] ?? null;

            if (!$id_status)   $errors[] = "Row $rowNumber: Status not found: '$statusName'";
            if (!$id_category) $errors[] = "Row $rowNumber: Category not found: '$categoryName'";

            $allowedLangs = ["english", "spanish", "portuguese", "french", "other"];
            if (!in_array(strtolower($language), $allowedLangs)) {
                $language = 'english';
            }

            $rows[] = [
                "name"        => trim($name),
                "email"       => trim($email),
                "phone"       => trim($phone),
                "address"     => trim($address),
                "id_status"   => $id_status,
                "id_category" => $id_category,
                "languaje"    => strtolower($language),
                "comments"    => mb_substr($comments, 0, 240)
            ];
        }

        fclose($handle);
        
        if (count($errors)) {
            $errorMessage = "CSV Import Failed!\n\n";
            $errorMessage .= "Please fix the following errors:\n";
            $errorMessage .= implode("\n", $errors);
            $errorMessage .= "\n\nTip: Make sure all required fields are filled and status/category names match exactly with your system.";
            
            $_SESSION['message'] = [
                "message" => $errorMessage,
                "title" => "CSV Import Failed",
                "type" => "error"
            ];
            
            MessageUtil::setMessage($errorMessage, "CSV Import Failed", "error");
            
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }

        foreach ($rows as $row) {
            $repo->add([
                ...$row,
                ...LoginService::getUserIdAsArray(true),
                ...LoginService::getOwnerAsArray()
            ]);
        }

        MessageUtil::setMessage("Leads imported successfully.");
        LocationUtils::reload();
    }

    if (isset($_POST["id"])) {
        $lead = $repo->getOne(["id" => $_POST["id"]]);

        if (!$lead) {
            MessageUtil::setMessage("Lead not found.");
            LocationUtils::reload();
        }

        if ($user->getLevel() === 4) {
            if ($lead->id_user !== $user->getId()) {
                MessageUtil::setMessage("Only administrators can delete leads. Please contact support.");
                LocationUtils::reload();
            }

            $repo->update(["id_user" => $lead->id_owner], ["id" => $lead->id]);
            MessageUtil::setMessage("Lead unassigned successfully.");
            LocationUtils::reload();
        }

        if ($user->getLevel() <= 3) {
            $repo->delete(["id" => $_POST["id"]]);
            MessageUtil::setMessage("Lead deleted successfully.");
            LocationUtils::reload();
        }

        MessageUtil::setMessage("You don't have permission to perform this action.");
        LocationUtils::reload();
    }
});

$router->run();
