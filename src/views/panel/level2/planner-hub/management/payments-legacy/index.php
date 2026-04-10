<?php

use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Repositories\StripeAccountsRepository;
use Stripe\Stripe;
use Stripe\Balance;
use Stripe\Payout;
use Stripe\BalanceTransaction;

$router = new Router();

// GET: Show Stripe balance and controls
$router->get(function () {
    $session = LoginService::getSession();
    $repo = new StripeAccountsRepository();
    $account = $repo->getByUser($session->getId());

    $available = 0;
    $pending = 0;
    $reserved = 0;
    $future_payout = 0;
    $currency = 'USD';
    $instant_enabled = false;
    $charges_enabled = false;
    $details_submitted = false;
    $payouts_enabled = false;
    $onboarding_link = null;
    $login_link = null;
    $in_transit = 0;
    $net_available = 0;

    if ($account && $account->stripe_account_id) {
        Stripe::setApiKey($_ENV["STRIPE_KEY"]);

        try {
            $acctDetails = \Stripe\Account::retrieve($account->stripe_account_id);
            $charges_enabled = $acctDetails->charges_enabled;
            $payouts_enabled = $acctDetails->payouts_enabled;
            $details_submitted = $acctDetails->details_submitted;

            $login_link = \Stripe\Account::createLoginLink($account->stripe_account_id)->url;

            if (!$charges_enabled || !$payouts_enabled || !$details_submitted) {
                $onboarding_link = \Stripe\AccountLink::create([
                    'account' => $account->stripe_account_id,
                    'refresh_url' => $_ENV["APP_URL"] . '/panel/planner-hub/management/payments/',
                    'return_url' => $_ENV["APP_URL"] . '/panel/planner-hub/management/payments/',
                    'type' => 'account_onboarding',
                ])->url;
            }

            $externalAccounts = \Stripe\Account::allExternalAccounts($account->stripe_account_id, ['limit' => 3]);
            foreach ($externalAccounts->data as $external) {
                if ($external->object === 'bank_account' && isset($external->available_payout_methods)) {
                    if (in_array('instant', $external->available_payout_methods)) {
                        $instant_enabled = true;
                        break;
                    }
                }
            }

            $is_verified = ($charges_enabled && $payouts_enabled && $details_submitted);

            $repo->update([
                "charges_enabled" => $charges_enabled ? 1 : 0,
                "payouts_enabled" => $payouts_enabled ? 1 : 0,
                "details_submitted" => $details_submitted ? 1 : 0,
                "express_enabled" => $instant_enabled ? 1 : 0,
                "is_verified" => $is_verified ? 1 : 0,
                "updated_at" => date("Y-m-d H:i:s")
            ], [
                "id_user" => $session->getId()
            ]);

            $balance = Balance::retrieve([], ['stripe_account' => $account->stripe_account_id]);
            $available = $balance->available[0]->amount / 100;
            $reserved = $balance->connect_reserved[0]->amount / 100;
            $currency = strtoupper($balance->available[0]->currency ?? 'usd');

            foreach ($balance->pending ?? [] as $p) {
                if ($p->currency === 'usd') {
                    $in_transit = $p->amount / 100;
                    break;
                }
            }

            $transactions = BalanceTransaction::all(['limit' => 100], [
                'stripe_account' => $account->stripe_account_id
            ]);
            foreach ($transactions->data as $txn) {
                if ($txn->status === 'pending' && $txn->currency === 'usd') {
                    $pending += $txn->net / 100;
                }
            }

            foreach ($transactions->data as $txn) {
                if ($txn->type === 'payment') {
                    $net_available += $txn->net;
                }
            }
            $net_available = $net_available / 100;

        } catch (\Exception $e) {
            MessageUtil::setMessage("Stripe error: " . $e->getMessage());
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "account" => $account,
        "available" => $available,
        "reserved" => $reserved,
        "pending" => $pending,
        "future_payout" => $future_payout,
        "currency" => $currency,
        "instant_enabled" => $instant_enabled,
        "charges_enabled" => $charges_enabled,
        "details_submitted" => $details_submitted,
        "payouts_enabled" => $payouts_enabled,
        "onboarding_link" => $onboarding_link,
        "login_link" => $login_link,
        "in_transit" => $in_transit,
        "net_available" => $net_available,
        "current_schedule" => $acctDetails->settings->payouts->schedule->interval ?? null
    ]);
});

// POST: Handle form actions (withdraw, update schedule, etc.)
$router->post(function () {
    $session = LoginService::getSession();
    $repo = new StripeAccountsRepository();

    if (isset($_POST["action"]) && $_POST["action"] === "withdraw") {
        $account = $repo->getByUser($session->getId());

        if (!$account || !$account->stripe_account_id) {
            MessageUtil::setMessage("Stripe account not connected.");
            LocationUtils::reload();
        }

        Stripe::setApiKey($_ENV["STRIPE_KEY"]);

        try {
            $balance = Balance::retrieve([], [
                'stripe_account' => $account->stripe_account_id
            ]);

            $available = $balance->available[0]->amount;

            if ($available < 200) {
                MessageUtil::setMessage("Minimum balance required for payout not met.");
                LocationUtils::reload();
            }

            $method = ($_POST["payout_type"] ?? 'standard') === 'instant' ? 'instant' : 'standard';

            Payout::create([
                'amount' => $available,
                'currency' => $balance->available[0]->currency,
                'method' => $method,
            ], [
                'stripe_account' => $account->stripe_account_id
            ]);

            MessageUtil::setMessage("✅ Payout initiated successfully.");
        } catch (\Exception $e) {
            MessageUtil::setMessage("❌ Error initiating payout: " . $e->getMessage());
        }

        LocationUtils::reload();
    }

    if (isset($_POST["action"]) && $_POST["action"] === "update_schedule") {
        $account = $repo->getByUser($session->getId());

        if ($account && $account->stripe_account_id && isset($_POST["schedule"])) {
            $interval = in_array($_POST["schedule"], ['manual', 'daily']) ? $_POST["schedule"] : 'manual';

            Stripe::setApiKey($_ENV["STRIPE_KEY"]);

            try {
                \Stripe\Account::update(
                    $account->stripe_account_id,
                    [
                        'settings' => [
                            'payouts' => [
                                'schedule' => [
                                    'interval' => $interval
                                ]
                            ]
                        ]
                    ]
                );

                MessageUtil::setMessage("✅ Payout schedule updated to: " . strtoupper($interval));
            } catch (\Exception $e) {
                MessageUtil::setMessage("❌ Stripe error: " . $e->getMessage());
            }
        }

        LocationUtils::reload();
    }

    // Save or update Stripe account info manually
    $data = [
        "id_user" => $session->getId(),
        "stripe_account_id" => $_POST["stripe_account_id"] ?? null,
        "details_submitted" => isset($_POST["details_submitted"]) ? 1 : 0,
        "charges_enabled" => isset($_POST["charges_enabled"]) ? 1 : 0,
        "created_at" => date("Y-m-d H:i:s")
    ];

    if ($repo->getByUser($session->getId())) {
        $repo->update($data, ["id_user" => $session->getId()]);
    } else {
        $repo->add($data);
    }

    LocationUtils::reload();
});

$router->run();
