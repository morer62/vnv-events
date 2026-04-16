<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

$router->get(function () {
    $cookieName = 'store_payment_success';
    $encodedPayload = $_COOKIE[$cookieName] ?? '';

    if (trim((string)$encodedPayload) === '') {
        header('Location: ' . LocationUtils::pathFor('store/home'));
        exit;
    }

    $decodedJson = base64_decode($encodedPayload, true);
    $payload = $decodedJson ? json_decode($decodedJson, true) : null;

    if (
        !$payload ||
        !is_array($payload) ||
        empty($payload['order_id']) ||
        empty($payload['public_token'])
    ) {
        setcookie($cookieName, '', time() - 3600, '/');
        header('Location: ' . LocationUtils::pathFor('store/home'));
        exit;
    }

    $orderId = (int)$payload['order_id'];
    $publicToken = (string)$payload['public_token'];
    $pricingMode = (string)($payload['pricing_mode'] ?? '');
    $guestName = (string)($payload['guest_name'] ?? '');
    $total = (float)($payload['total'] ?? 0);
    $email = (string)($payload['email'] ?? '');

    // clear cookie so the page cannot be re-opened manually and analytics stay clean
    setcookie($cookieName, '', time() - 3600, '/');

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'order_id' => $orderId,
        'public_token' => $publicToken,
        'pricing_mode' => $pricingMode,
        'guest_name' => $guestName,
        'total' => $total,
        'email' => $email,
    ]);
});

$router->run();