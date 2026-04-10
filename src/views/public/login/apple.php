<?php

use App\Repositories\UserRepository;
use App\Services\AppleService\AppleSignInService;
use App\Services\ConfigService;
use App\Services\LoginService;
use App\Utils\ErrorLogging;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

if (!isset($_POST["code"])) {
    MessageUtil::setMessage("An exception occurred. Please try again.");
    LocationUtils::redirectInternal("login");
}

try {
    $code = $_POST["code"];
    $state = $_POST["state"];
    $name = $_POST["name"];


    $userRepo = new UserRepository();
    $userDTO = (new AppleSignInService(
        ConfigService::$APPLE_REDIRECT_SIGN_IN_URL,
        $code
    ))->handleSignUp();

    $user_id = $userDTO->sub;
    $user = $userRepo->getOne(["apple_id" => $user_id]);

    if (!$user) {
        MessageUtil::setMessage("User not found with this Apple account. Please sign up first.");
        LocationUtils::redirectInternal("signup");
    }

    if ((int) $user->is_active === 0) {
        MessageUtil::setMessage("Your account is inactive.");
        LocationUtils::redirectInternal("login");
    }

    LoginService::authenticateFromUserDbo($user);

    if (str_contains($user->email, "privaterelay.appleid.com")) {
        MessageUtil::setMessage("Please update your info.");
        LocationUtils::redirectInternal("panel/settings");
    }

    LocationUtils::redirectInternal("panel/home");
} catch (Exception $exception) {
    ErrorLogging::log($exception);
    MessageUtil::setMessage("An exception occurred. Please try again.");
    LocationUtils::redirectInternal("signup");
}