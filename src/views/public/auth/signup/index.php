<?php

use App\Repositories\UserRepository;
use App\Services\AppleService\AppleSignInService;
use App\Services\HashService;
use App\Services\AffiliateService;
use App\Utils\FormatPhone;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Response;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['APP_URL'] . "/signup");
$client->addScope("email");
$client->addScope("profile");
$client->addScope("https://www.googleapis.com/auth/calendar.events");

if (\App\Services\LoginService::getSession() !== null) {
    \App\Utils\LocationUtils::redirectInternal("panel/home");
    exit;
}

$router->get(function () use ($client) {
    $level = $_GET['level'] ?? null;
    $code = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? $level;
    $fromAffiliate = $_GET['from_affiliate'] ?? null;

    if ($code) {
        handleGoogleCallback($client, $code, $state);
        exit();
    }

    if (!$level) {
        return TemplateResponse::render(__DIR__ . "/choose.twig", [
            "from_affiliate" => $fromAffiliate
        ]);
    }

    $client->setState($level);
    $googleAuthUrl = $client->createAuthUrl();

    $affiliateService = new AffiliateService();
    $affiliateData = $affiliateService->getAffiliateFromCookie();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "level" => $level,
        "googleAuthUrl" => $googleAuthUrl,
        "apple_url" => AppleSignInService::getAppleSignUpUrl($level),
        "from_affiliate" => $fromAffiliate,
        "affiliate_data" => $affiliateData
    ]);
});

$router->post(function () {
    $userRepository = new UserRepository();
    $affiliateService = new AffiliateService();
    
    $password = $_POST["password"];
    $passwordConfirmation = $_POST["passwordConfirmation"];

    if ($password !== $passwordConfirmation) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return \App\Utils\JsonResponse::createResponse([
                'success' => false,
                'message' => 'Passwords must match'
            ]);
        }
        return Response::createResponse("Passwords must match");
    }

    $userExists = $userRepository->getOne(["email" => $_POST["email"]]);
    if ($userExists != null) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return \App\Utils\JsonResponse::createResponse([
                "success" => false,
                "message" => "Email already registered"
            ]);
        }
        MessageUtil::setMessage("User already exists");
        LocationUtils::redirectInternal('signup');
    }

    $days = intval($_ENV['FREE_MEMBERSHIP_DAYS']);
    $dueDate = date('Y-m-d', strtotime("+{$days} days"));
    $level = intval($_POST["level"]);
    $id_owner = null;

    if ($level === 5) {
        $admins = $userRepository->getAllFlexible(["level" => 1]);
        if (count($admins) > 0) {
            $id_owner = $admins[0]->id;
        }
    }

    $userData = [
        'name' => $_POST["name"],
        'lastname' => $_POST["lastname"],
        'email' => $_POST["email"],
        'password' => HashService::hashPassword($password),
        'phone' => FormatPhone::formatPhone($_POST["phoneNumber"]),
        'phone_code' => '',
        'phone_validation' => 1,
        'membership_due_date' => $dueDate,
        'level' => $level,
        'id_owner' => $id_owner,
        'password_updated' => 1
    ];

    $userRepository->add($userData);
    $user_id = $userRepository->getLastId();

    if (in_array($level, [2, 3])) {
        $userRepository->update(["id_owner" => $user_id], ["id" => $user_id]);
    }

    try {
        $affiliateData = $affiliateService->getAffiliateFromCookie();
        if ($affiliateData && isset($affiliateData['referrer_id']) && $affiliateData['referrer_id']) {
            $affiliateService->registerReferral($user_id, $affiliateData);
        }
    } catch (\Exception $e) {
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $loginService = new \App\Services\LoginService();
        $loginService->authenticate($_POST["email"], $password);
        
        return \App\Utils\JsonResponse::createResponse([
            "success" => true,
            "message" => "Account created successfully"
        ]);
    }
    
    MessageUtil::setMessage("You have been registered successfully");
    LocationUtils::redirectInternal('login');
});

function handleGoogleCallback($client, $code, $level)
{
    try {
        $userRepository = new UserRepository();
        $affiliateService = new AffiliateService();

        $token = $client->fetchAccessTokenWithAuthCode($code);
        $client->setAccessToken($token);

        $google_service = new Google\Service\Oauth2($client);
        $data = $google_service->userinfo->get();

        $existingUser = $userRepository->getOne(["email" => $data->email]);
        if ($existingUser != null) {
            MessageUtil::setMessage("User already exists");
            LocationUtils::redirectInternal('signup');
            return;
        }

        $days = intval($_ENV['FREE_MEMBERSHIP_DAYS']);
        $dueDate = date('Y-m-d', strtotime("+{$days} days"));
        $level = intval($level ?? 1);
        $id_owner = null;

        if ($level === 5) {
            $admins = $userRepository->getAllFlexible(["level" => 1]);
            if (count($admins) > 0) {
                $id_owner = $admins[0]->id;
            }
        }

        $userData = [
            'name' => $data->given_name ?? $data->name,
            'lastname' => $data->family_name ?? '',
            'email' => $data->email,
            'password' => HashService::hashPassword(bin2hex(random_bytes(16))),
            'phone' => '',
            'phone_code' => '',
            'phone_validation' => 1,
            'membership_due_date' => $dueDate,
            'level' => $level,
            'google_id' => $data->id,
            'google_token' => json_encode($token),
            'id_owner' => $id_owner,
            'password_updated' => 1
        ];

        $userRepository->add($userData);
        $user_id = $userRepository->getLastId();

        if (in_array($level, [2, 3])) {
            $userRepository->update(["id_owner" => $user_id], ["id" => $user_id]);
        }

        try {
            $affiliateData = $affiliateService->getAffiliateFromCookie();
            if ($affiliateData && isset($affiliateData['referrer_id']) && $affiliateData['referrer_id']) {
                $affiliateService->registerReferral($user_id, $affiliateData);
            }
        } catch (\Exception $e) {
        }

        LocationUtils::redirectInternal('login');
    } catch (Exception $e) {
        MessageUtil::setMessage("Error with Google signup: " . $e->getMessage());
        LocationUtils::redirectInternal('signup');
    }
}

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
