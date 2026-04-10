<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

use App\Services\LoginService;
use App\Repositories\StripeAccountsRepository;
use App\Utils\LocationUtils;

$stripe = new \Stripe\StripeClient($_ENV["STRIPE_KEY"]);
$session = LoginService::getSession();
$repo = new StripeAccountsRepository();

$existing = $repo->getByUser($session->getId());

if ($existing && !empty($existing->stripe_account_id)) {
    $accountId = $existing->stripe_account_id;
} else {
    $account = $stripe->accounts->create([
        'type' => 'express',
        'email' => $session->getEmail(),
    ]);

    $repo->add([
        "id_user" => $session->getId(),
        "stripe_account_id" => $account->id,
        "is_verified" => 0,
        "onboarded_at" => null
    ]);

    $accountId = $account->id;
}

$accountLink = $stripe->accountLinks->create([
    'account' => $accountId,
    'refresh_url' => $_ENV["APP_URL"] . '/panel/planner-hub/team/payments/',
    'return_url' => $_ENV["APP_URL"] . '/panel/planner-hub/team/payments/',
    'type' => 'account_onboarding',
]);

header("Location: " . $accountLink->url);
exit;
