<?php

use App\Repositories\ClientAutoChargeConsentsRepository;
use App\Services\ClientPaymentMethodService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $session = LoginService::getSession();
    if (!$session || (int)$session->getLevel() !== 5) {
        LocationUtils::redirectInternal('/login');
    }

    $clientId = (int)$session->getId();
    $service = new ClientPaymentMethodService();
    $consentsRepo = new ClientAutoChargeConsentsRepository();
    $methods = $service->listClientSavedPaymentMethodsAcrossBusinesses($clientId);

    $consentsByMethod = [];
    foreach ($consentsRepo->listLatestByClient($clientId) as $consent) {
        $methodId = (int)($consent->saved_payment_method_id ?? 0);
        if ($methodId > 0 && !isset($consentsByMethod[$methodId])) {
            $consentsByMethod[$methodId] = $consent;
        }
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'methods' => $methods,
        'consents_by_method' => $consentsByMethod,
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    if (!$session || (int)$session->getLevel() !== 5) {
        LocationUtils::redirectInternal('/login');
    }

    $clientId = (int)$session->getId();
    $methodId = (int)($_POST['method_id'] ?? 0);
    $businessId = (int)($_POST['business_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $service = new ClientPaymentMethodService();

    if ($methodId <= 0) {
        MessageUtil::setMessage('Invalid payment method.', 'Error', 'danger');
        LocationUtils::redirectInternal('panel/payment-methods');
    }

    if ($action === 'authorize' && $businessId > 0) {
        $method = $service->getActiveMethodForClientProvider($methodId, $businessId, $clientId, strtolower((string)($_POST['provider'] ?? '')));
        if ($method) {
            $service->recordAutoChargeConsent([
                'id_user_business' => $businessId,
                'id_client' => $clientId,
                'user_id' => $clientId,
                'payment_provider' => $method->payment_provider,
                'saved_payment_method_id' => $methodId,
                'source' => 'client_payment_methods_panel',
            ]);
            MessageUtil::setMessage('Future charge authorization enabled.');
        }
    } elseif ($action === 'revoke' && $businessId > 0) {
        $service->revokeConsentForMethod($businessId, $clientId, $methodId);
        MessageUtil::setMessage('Future charge authorization revoked.');
    } elseif ($action === 'delete') {
        $service->deactivateMethod($methodId, $clientId);
        MessageUtil::setMessage('Payment method removed.');
    }

    LocationUtils::redirectInternal('panel/payment-methods');
});

$router->run();
