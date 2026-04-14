<?php

use App\Services\LoginService;
use App\Services\HashService;
use App\Repositories\UserRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (!isset($_SESSION['pending_password_user_id'])) {
        LocationUtils::redirectInternal("login");
    }
    
    $userId = $_SESSION['pending_password_user_id'];
    
    $userRepo = new UserRepository();
    $fullUser = $userRepo->getOneWithoutOwnership(['id' => $userId]);
    
    if (!$fullUser) {
        unset($_SESSION['pending_password_email']);
        unset($_SESSION['pending_password_user_id']);
        LocationUtils::redirectInternal("login");
    }
    
    $needsPasswordUpdate = (
        isset($fullUser->password_updated) && (int)$fullUser->password_updated === 0 && 
        isset($fullUser->level) && in_array((int)$fullUser->level, [4, 5]) &&
        empty($fullUser->google_id) &&
        empty($fullUser->apple_id)
    );
    
    if (!$needsPasswordUpdate) {
        unset($_SESSION['pending_password_email']);
        unset($_SESSION['pending_password_user_id']);
        LocationUtils::redirectInternal("panel/home");
    }
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $fullUser
    ]);
});

$router->post(function () {
    if (!isset($_SESSION['pending_password_user_id'])) {
        LocationUtils::redirectInternal("login");
    }
    
    $userId = $_SESSION['pending_password_user_id'];
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        MessageUtil::setMessage("Please fill in all fields.");
        LocationUtils::reload();
    }
    
    if ($newPassword !== $confirmPassword) {
        MessageUtil::setMessage("Passwords do not match.");
        LocationUtils::reload();
    }
    
    if (strlen($newPassword) < 8) {
        MessageUtil::setMessage("Password must be at least 8 characters long.");
        LocationUtils::reload();
    }
    
    $userRepo = new UserRepository();
    $userRepo->updateData($userId, [
        'password' => HashService::hashPassword($newPassword),
        'password_updated' => 1
    ]);
    
    unset($_SESSION['pending_password_email']);
    unset($_SESSION['pending_password_user_id']);
    
    MessageUtil::setMessage("Password updated successfully! Please log in with your new password.");
    LocationUtils::redirectInternal("login");
});

$router->run();
