<?php

use App\Services\LoginService;
use App\Repositories\UserRepository;
use App\Repositories\StoreSubscriptionsRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

// 🔹 Render vista principal
$router->get(function () {
    $user = LoginService::getSession();

    $subscriptionsRepo = new StoreSubscriptionsRepository();
    $userId = $user ? (int)$user->getId() : 0;
    $email = $user && method_exists($user, 'getEmail') ? (string)$user->getEmail() : '';

    $subscriptions = [];
    if ($userId > 0) {
        $subscriptions = $subscriptionsRepo->getAllByUser($userId, 10);
    }
    if ((!$subscriptions || count($subscriptions) === 0) && trim($email) !== '') {
        $subscriptions = $subscriptionsRepo->getAllByEmail($email, 10);
    }

    $currentSubscription = $subscriptions[0] ?? null;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user,
        "current_subscription" => $currentSubscription
    ]);
});

// 🔹 Cambiar nivel de usuario y redirigir
$router->post(function () {
    $user = LoginService::getSession();
    $newLevel = (int)($_POST['level'] ?? 0);

    $action = trim((string)($_POST['action'] ?? ''));
    if (in_array($action, ['pause_subscription', 'resume_subscription'], true)) {
        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        if ($subscriptionId <= 0) {
            MessageUtil::setMessage('Subscription not found.');
            LocationUtils::redirectInternal("panel/home");
            return;
        }

        $repo = new StoreSubscriptionsRepository();
        $subscription = $repo->getOne(['id' => $subscriptionId]);
        if (!$subscription) {
            MessageUtil::setMessage('Subscription not found.');
            LocationUtils::redirectInternal("panel/home");
            return;
        }

        $sessionUserId = $user ? (int)$user->getId() : 0;
        $sessionEmail = $user && method_exists($user, 'getEmail') ? strtolower(trim((string)$user->getEmail())) : '';

        $subscriptionUserId = (int)($subscription->id_user ?? 0);
        $subscriptionEmail = strtolower(trim((string)($subscription->email ?? '')));
        $isOwnerByUserId = $subscriptionUserId > 0 && $subscriptionUserId === $sessionUserId;
        $isOwnerByEmail = $subscriptionEmail !== '' && $subscriptionEmail === $sessionEmail;

        if (!$isOwnerByUserId && !$isOwnerByEmail) {
            MessageUtil::setMessage('You cannot modify this subscription.');
            LocationUtils::redirectInternal("panel/home");
            return;
        }

        $status = strtoupper((string)($subscription->status ?? ''));
        if ($action === 'pause_subscription') {
            if ($status !== StoreSubscriptionsRepository::STATUS_ACTIVE) {
                MessageUtil::setMessage('Only active subscriptions can be paused.');
                LocationUtils::redirectInternal("panel/home");
                return;
            }
            $ok = $repo->pause($subscriptionId);
            MessageUtil::setMessage($ok ? 'Subscription paused successfully.' : 'Could not pause subscription.');
            LocationUtils::redirectInternal("panel/home");
            return;
        }

        if ($action === 'resume_subscription') {
            if ($status !== StoreSubscriptionsRepository::STATUS_PAUSED) {
                MessageUtil::setMessage('Only paused subscriptions can be resumed.');
                LocationUtils::redirectInternal("panel/home");
                return;
            }
            $ok = $repo->activate($subscriptionId);
            MessageUtil::setMessage($ok ? 'Subscription resumed successfully.' : 'Could not resume subscription.');
            LocationUtils::redirectInternal("panel/home");
            return;
        }
    }

    if (!$user || !in_array($newLevel, [2, 3, 4, 5])) {
        LocationUtils::redirectInternal("panel/home");
        return;
    }

    // Update en base de datos
    $repo = new UserRepository();
    $repo->update(["level" => $newLevel], ["id" => $user->getId()]);

    // Refrescar objeto en sesión
    $user->setLevel($newLevel);
    LoginService::setSession($user);

    // 🔹 Redirigir directo al dashboard
    LocationUtils::redirectInternal("panel/");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
