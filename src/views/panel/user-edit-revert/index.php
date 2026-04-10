<?php

use App\Services\LoginService;
use App\Services\UserEditService;
use App\Repositories\UserRepository;

$user = LoginService::getSession();

if (!$user) {
    header('Location: /login');
    exit;
}

$userId = $_GET['user_id'] ?? null;
$logId = $_GET['log_id'] ?? null;

if (!$userId || !$logId) {
    header('Location: /panel/planner-hub/management/users');
    exit;
}

$userRepo = new UserRepository();
$editService = new UserEditService();

// Get user details
$userDetails = $userRepo->getById($userId);
if (!$userDetails) {
    header('Location: /panel/planner-hub/management/users');
    exit;
}

// Get edit history
$editHistory = $editService->getUserEditHistory($userId);

// Find the specific log entry
$logEntry = null;
foreach ($editHistory as $entry) {
    if ($entry->id == $logId) {
        $logEntry = $entry;
        break;
    }
}

if (!$logEntry) {
    header('Location: /panel/planner-hub/management/users');
    exit;
}

// Handle revert action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revert'])) {
    $changes = json_decode($logEntry->changes, true);
    
    if ($changes) {
        $revertData = [];
        foreach ($changes as $field => $change) {
            $revertData[$field] = $change['old'];
        }
        
        // Update user with old values
        $updated = $userRepo->update($userId, $revertData);
        
        if ($updated) {
            // Log the revert action
            $editService->logUserChanges($userId, $userDetails, $revertData);
            
            $_SESSION['success_message'] = 'User profile reverted successfully.';
            header('Location: /panel/planner-hub/management/users');
            exit;
        } else {
            $_SESSION['error_message'] = 'Failed to revert user profile.';
        }
    }
}

$title = 'Revert User Profile Changes';
$user = $userDetails;
$log = $logEntry;
