<?php

use App\Services\AppleService\AppleSignInService;
use App\Services\LoginService;
use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use Google\Service\Oauth2;
use Google\Service\Calendar;

$router = new Router();

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['APP_URL'] . "/login");
$client->addScope("email");
$client->addScope("profile");
$client->addScope(Calendar::CALENDAR_EVENTS);

if (\App\Services\LoginService::getSession() !== null) {
    \App\Utils\LocationUtils::redirectInternal("panel/home");
    exit;
}

$router->get(function () use ($client) {

    $code = $_GET['code'] ?? null;
    if ($code) {
        return handleGoogleLogin($client, $code);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "googleAuthUrl" => $client->createAuthUrl(),
        "apple_url" => AppleSignInService::getAppleSignInUrl()
    ]);
});

$router->post(function () {
    try {
        $email = $_POST['email'];
        $password = $_POST['password'];

        $loginService = new LoginService();
        
        try {
            $user = $loginService->verifyCredentials($email, $password);
        } catch (Exception $e) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                return \App\Utils\JsonResponse::createResponse([
                    "success" => false,
                    "message" => $e->getMessage()
                ]);
            }
            MessageUtil::setMessage($e->getMessage());
            LocationUtils::redirectInternal("login");
        }

        $userRepo = new UserRepository();
        $fullUser = $userRepo->getOneWithoutOwnership(['id' => $user->id]);
        
        $needsPasswordUpdate = (
            isset($fullUser->password_updated) && (int)$fullUser->password_updated === 0 && 
            isset($fullUser->level) && in_array((int)$fullUser->level, [4, 5]) &&
            empty($fullUser->google_id) &&
            empty($fullUser->apple_id)
        );

        if ($needsPasswordUpdate) {
            $_SESSION['pending_password_email'] = $email;
            $_SESSION['pending_password_user_id'] = $user->id;
        } else {
            $loginService->authenticate($email, $password);
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return \App\Utils\JsonResponse::createResponse([
                "success" => true,
                "message" => "Login successful",
                "needs_password_update" => $needsPasswordUpdate
            ]);
        }

        if ($needsPasswordUpdate) {
            LocationUtils::redirectInternal('update-password');
        } else {
            LocationUtils::redirectInternal('panel/home');
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }
});

function handleGoogleLogin($client, $code): never
{
    try {
        $userRepository = new UserRepository();

        $token = $client->fetchAccessTokenWithAuthCode($code);
        $client->setAccessToken($token);

        $google_service = new Oauth2($client);
        $data = $google_service->userinfo->get();

        $user = $userRepository->getOneWithoutOwnership([
            "email" => $data->email
        ]);

        if (!$user) {
            MessageUtil::setMessage("No account found with this Google account. Please sign up first.");
            LocationUtils::redirectInternal('signup');
        }

        if ((int) $user->is_active === 0) {
            MessageUtil::setMessage("Your account is inactive.");
            LocationUtils::redirectInternal('login');
        }

        $userRepository->updateData($user->id, [
            'google_id' => $data->id,
            'google_token' => json_encode($token)
        ]);

        LoginService::authenticateFromUserDbo($user);
        LocationUtils::redirectInternal('panel/home');

    } catch (Exception $e) {
        MessageUtil::setMessage("Error with Google login: " . $e->getMessage());
        LocationUtils::redirectInternal('login');
    }
}

try {
    $router->run();
} catch (Exception $e) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        return \App\Utils\JsonResponse::createResponse([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    echo $e->getMessage();
}
