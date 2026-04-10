<?php

use App\Repositories\MembershipPaymentsRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;


$user = LoginService::getSession();
$membershipActive = $user->hasActiveMembership();
$membershipDue = $user->getMembershipDueDate();

$isMobileApp = isset($_SESSION['IS_MOBILE_APP']) && $_SESSION['IS_MOBILE_APP'] === true;

$repo = new MembershipPaymentsRepository();
$payments = $repo->getAllByUserId($user->getId());

$cardRepo = new UserCardsRepository();
$hasCard = !empty($cardRepo->getByUserId($user->getId()));
$mainCard = $cardRepo->getMainCardByUserId($user->getId());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isMobileApp) {
        MessageUtil::setMessage("⚠️ Payments are not available in the mobile app. Please visit our website to complete your membership payment.");
        LocationUtils::reload();
    }

    $plan = $_POST['plan'] ?? '';
    $amount = 0;
    $days = 0;

    if ($plan === 'monthly') {
        $amount = floatval($_ENV['MEMBERSHIP_VALUE']);
        $days = 30;
    } elseif ($plan === 'annual') {
        $amount = floatval($_ENV['MEMBERSHIP_ANNUAL_VALUE']);
        $days = 365;
    } else {
        MessageUtil::setMessage("Invalid plan selected.");
        LocationUtils::reload();
    }
    
    $cards = $cardRepo->getByUserId($user->getId());

    if (empty($cards)) {
        MessageUtil::setMessage("No card found. Please add one.");
        LocationUtils::redirectInternal("panel/cards");
    }

    $card = $cards[0];
    $stripe = new StripeService();
    $success = $stripe->createChargeV1($card->token, $amount);

    if (!$success) {
        MessageUtil::setMessage("Payment failed. Please try again.");
        LocationUtils::reload();
    }

    // Guardar el pago
    $repo->add([
        'id_user' => $user->getId(),
        'payment_date' => date('Y-m-d'),
        'amount' => $amount,
        'plan' => $plan
    ]);

    // Actualizar fecha de vencimiento
    $newDueDate = new DateTime();
    $existingDue = $user->getMembershipDueDate();
    if ($existingDue) {
        $dueDateObj = new DateTime($existingDue);
        if ($dueDateObj > $newDueDate) {
            $newDueDate = $dueDateObj;
        }
    }
    $newDueDate->modify("+$days days");

    $userRepo = new UserRepository();
    $userRepo->update([
        'membership_due_date' => $newDueDate->format('Y-m-d')
    ], ['id' => $user->getId()]);

    MessageUtil::setMessage("Membership renewed until " . $newDueDate->format('Y-m-d') . ".");
    LocationUtils::reload();
}

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "membershipActive" => $membershipActive,
    "membershipDue" => $membershipDue,
    "payments" => $payments,
    "monthlyPrice" => $_ENV["MEMBERSHIP_VALUE"],
    "annualPrice" => $_ENV["MEMBERSHIP_ANNUAL_VALUE"],
    "hasCard" => $hasCard,
    "mainCard" => $mainCard,
    "isMobileApp" => $isMobileApp,
    "websiteUrl" => $_ENV["APP_URL"] ?? "https://ophyra.com"
]);
