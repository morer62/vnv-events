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

    // Forzar payout manual para evitar auto-transferencias
    $stripe->accounts->update(
        $account->id,
        [
            'settings' => [
                'payouts' => [
                    'schedule' => ['interval' => 'manual']
                ]
            ]
        ]
    );


    $repo->add([
        "id_user" => $session->getId(),
        "stripe_account_id" => $account->id,
        "is_verified" => 0,
        "onboarded_at" => null
    ]);

    $accountId = $account->id;
}


$updated = $stripe->accounts->retrieve($account->id);
var_dump($updated->settings->payouts->schedule->interval); 


$accountLink = $stripe->accountLinks->create([
    'account' => $accountId,
    'refresh_url' => $_ENV["APP_URL"] . '/panel/planner-hub/management/payments/',
    'return_url' => $_ENV["APP_URL"] . '/panel/planner-hub/management/payments/',
    'type' => 'account_onboarding',
]);

header("Location: " . $accountLink->url);
exit;
