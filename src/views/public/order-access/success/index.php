<?php

use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\SquareAccountsRepository;
use App\Repositories\TipsRepository;

$token = $_GET["token"] ?? null;
$next = $_GET["next"] ?? null;

if (!$token) {
    LocationUtils::redirectInternal("/404");
}

$decoded = json_decode(base64_decode($token), true);
if (!$decoded || !isset($decoded["order_id"])) {
    LocationUtils::redirectInternal("/404");
}

$orderId = intval($decoded["order_id"]);
$orderRepo = new OrdersRepository();
$order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);

if ($order) {
    $order = (object)$order;
}

if (!$order) {
    LocationUtils::redirectInternal("/404");
}

$nextUrl = null;
if ($next === "second") {
    $nextUrl = "order-access/second/?token=" . urlencode($token);
}

$showTipOption = false;
$orderTotal = 0;

if (empty($order->id_tip) && empty($next) && !empty($order->id_owner)) {
    $paymentsRepo = new OrdersPaymentsRepository();
    $payments = $paymentsRepo->getAllBy(["id_order" => $orderId]);
    
    $mainPayments = array_filter($payments, function($p) {
        return empty($p->id_suborder) || $p->id_suborder == 0;
    });
    
    $totalPaid = 0;
    foreach ($mainPayments as $p) {
        $totalPaid += (float)$p->amount;
    }
    
    $orderTotal = $orderRepo->calculateTotal($orderId);
    
    if ($totalPaid >= $orderTotal) {
        $showTipOption = true;
        
        $accountRepo = new SquareAccountsRepository();
        $squareAccount = $accountRepo->getByUser((int)$order->id_owner);
    }
}

$tipsRepo = new TipsRepository();
$suggestedTips = $tipsRepo->getActiveTips();

// Obtener información del cliente
$clientEmail = '';
if (!empty($order->id_client)) {
    $userRepo = new \App\Repositories\UserRepository();
    $client = $userRepo->getOne(["id" => $order->id_client]);
    if ($client && !empty($client->email)) {
        $clientEmail = $client->email;
    }
}

$baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $baseUrl = str_replace('http://', 'https://', $baseUrl);
}

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "token" => $token,
    "next_url" => $nextUrl,
    "show_tip_option" => $showTipOption,
    "order" => $order,
    "order_total" => $orderTotal,
    "suggested_tips" => $suggestedTips,
    "client_email" => $clientEmail,
    "square_application_id" => $_ENV["SQUARE_APPLICATION_ID"] ?? "",
    "square_location_id" => $_ENV["SQUARE_LOCATION_ID"] ?? "",
    "square_environment" => $_ENV["SQUARE_ENVIRONMENT"] ?? "sandbox",
    "base_url" => $baseUrl
]);
