<?php

use App\Services\LoginService;
use App\Repositories\StripeAccountsRepository;
use App\Services\StripeServiceV2;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;


$userSession = LoginService::getSession();
$stripeService = new StripeServiceV2();
$stripeAccountRepository = new StripeAccountsRepository();

$existing = $stripeAccountRepository->getByUser($userSession->getId());

if ($existing && !empty($existing->stripe_account_id)) {
    $accountId = $existing->stripe_account_id;
} else {
    $account = $stripeService->createAccount($userSession);

    $stripeAccountRepository->add([
        "id_user" => $userSession->getId(),
        "stripe_account_id" => $account->id,
        "is_verified" => 0,
        "onboarded_at" => null
    ]);

    $accountId = $account->id;
}

$accountLink = $stripeService->createAccountLink($accountId);

if ($accountLink) {
    LocationUtils::redirectTo($accountLink->url);
} else {
    MessageUtil::setMessage("Error creating account link", "error");
    LocationUtils::redirectTo("panel/planner-hub/management");
}