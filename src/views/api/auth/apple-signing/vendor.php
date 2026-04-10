<?php


use App\Repositories\UserRepository;
use App\Services\AppleService\AppleSignInService;
use App\Services\ConfigService;
use App\Services\HashService;
use App\Services\LoginService;
use App\Utils\ErrorLogging;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

if (!isset($_POST["code"])) {
    MessageUtil::setMessage("An exception occurred. Please try again.");
    LocationUtils::redirectInternal("signup");
}

$code = $_POST["code"];
$state = $_POST["state"];
$name = $_POST["name"];


try {
    $userRepo = new UserRepository();

    $userDTO = (new AppleSignInService(
        ConfigService::$APPLE_REDIRECT_SIGN_UP_URL . "/vendor",
        $code
    ))->handleSignUp();

    $user_id = $userDTO->sub;
    $email = $userDTO->email ?? null;

    $user = $userRepo->getOne(["apple_id" => $user_id]);

    if ($user) {
        MessageUtil::setMessage("Account already exists.");
        LocationUtils::redirectInternal("login");
    }

    $id_owner = null;
    $admins = $userRepo->getAllFlexible(["level" => 1]);
    if (count($admins) > 0) {
        $id_owner = $admins[0]->id;
    }

    $days = intval($_ENV['FREE_MEMBERSHIP_DAYS']);
    $dueDate = date('Y-m-d', strtotime("+{$days} days"));

    $userRepo->add([
        'name' => $name ?? "",
        'lastname' => '',
        'email' => $userDTO->email,
        'password' => HashService::hashPassword(bin2hex(random_bytes(16))),
        'phone' => '',
        'phone_code' => '',
        'phone_validation' => 1,
        'membership_due_date' => $dueDate,
        'level' => 5,
        'id_owner' => $id_owner,
        'apple_id' => $user_id,
    ]);

    $user_id = $userRepo->getLastId();
    $userRepo->update(["id_owner" => $user_id], ["id" => $user_id]);

    $userDbo = $userRepo->getOne(["id" => $user_id]);
    LoginService::authenticateFromUserDbo($userDbo);
    MessageUtil::setMessage("Account created successfully. \nPlease update your info.");
    LocationUtils::redirectInternal("panel/settings");
} catch (Exception $e) {
    ErrorLogging::log($e);
    MessageUtil::setMessage("An exception occurred. Please try again.");
    LocationUtils::redirectInternal("signup");
}
