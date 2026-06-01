<?php

use App\Repositories\ClientsUsersRepository;
use App\Repositories\UserRepository;
use App\Services\HashService;
use App\Services\LoginService;
use App\Utils\CSRF;
use App\Utils\FormatPhone;
use App\Utils\JsonResponse;

try {
    CSRF::validateCSRF();

    $name = trim($_POST['name'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $redirect = trim($_POST['redirect'] ?? '/forums/');

    if ($name === '' || $lastname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        JsonResponse::createResponse([
            'success' => false,
            'message' => 'Please complete the form with a valid email and a password of at least 8 characters.',
        ], 422)->handle();
        exit;
    }

    $userRepository = new UserRepository();
    $existing = $userRepository->getOneWithoutOwnership(['email' => $email]);

    if ($existing) {
        if ((int)$existing->level !== 5) {
            JsonResponse::createResponse([
                'success' => false,
                'message' => 'This email already belongs to another account type. Please log in instead.',
            ], 409)->handle();
            exit;
        }

        try {
            $loginService = new LoginService();
            $loginService->authenticate($email, $password);
        } catch (Throwable $e) {
            JsonResponse::createResponse([
                'success' => false,
                'message' => 'This email already exists. Please log in with your password.',
            ], 409)->handle();
            exit;
        }

        JsonResponse::createResponse([
            'success' => true,
            'redirect' => safe_redirect($redirect),
        ])->handle();
        exit;
    }

    $admins = $userRepository->getAllFlexible(['level' => 1]);
    $ownerId = count($admins) > 0 ? (int)$admins[0]->id : null;
    $days = intval($_ENV['FREE_MEMBERSHIP_DAYS'] ?? 365);
    $dueDate = date('Y-m-d', strtotime("+{$days} days"));

    $userRepository->add([
        'name' => $name,
        'lastname' => $lastname,
        'email' => $email,
        'password' => HashService::hashPassword($password),
        'phone' => FormatPhone::formatPhone($_POST['phone'] ?? ''),
        'phone_code' => '',
        'phone_validation' => 1,
        'membership_due_date' => $dueDate,
        'membership_type' => 'FREE',
        'level' => 5,
        'id_owner' => $ownerId,
        'is_active' => 1,
    ]);

    $userId = $userRepository->getLastId();

    if ($ownerId) {
        (new ClientsUsersRepository())->create($userId, $ownerId);
    }

    $loginService = new LoginService();
    $loginService->authenticate($email, $password);

    JsonResponse::createResponse([
        'success' => true,
        'redirect' => safe_redirect($redirect),
    ])->handle();
} catch (Throwable $e) {
    JsonResponse::createResponse([
        'success' => false,
        'message' => 'Could not create the account right now.',
    ], 500)->handle();
}

function safe_redirect(string $redirect): string
{
    if (preg_match('#^https://vnvevents\.com/#', $redirect)) {
        return $redirect;
    }

    if (str_starts_with($redirect, '/')) {
        return $redirect;
    }

    return '/forums/';
}
