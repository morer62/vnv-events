<?php

use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\Connection;

$sessionUser = LoginService::getSession();
if (!$sessionUser || (int)$sessionUser->getLevel() !== 1) {
    LocationUtils::redirectInternal('/panel/home');
}

$router = new Router();

function validateProviderFields(string $type, array $data): ?string
{
    if ($type === 'square') {
        if (trim($data['api_key'] ?? '') === '') return "Square requires Access Token (API key).";
        if (trim($data['public_key'] ?? '') === '') return "Square requires Application ID (Public key).";
        if (trim((string)($data['location_id'] ?? '')) === '') return "Square requires Location ID.";
        return null;
    }
    if ($type === 'stripe') {
        if (trim($data['api_key'] ?? '') === '') return "Stripe requires Secret Key (API key).";
        if (trim($data['public_key'] ?? '') === '') return "Stripe requires Publishable Key (Public key).";
        return null;
    }
    if ($type === 'paypal') {
        if (trim((string)($data['merchant_email'] ?? '')) === '') return "PayPal requires Merchant Email.";
        return null;
    }
    return "Invalid provider type.";
}

function testProviderCredentials(object $provider): array
{
    $type = (string)($provider->provider_type ?? '');

    if ($type === 'stripe') {
        try {
            \Stripe\Stripe::setApiKey((string)($provider->api_key ?? ''));
            \Stripe\Balance::retrieve();
            return ['success' => true, 'message' => 'Stripe credentials are valid.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Stripe test failed: ' . $e->getMessage()];
        }
    }

    if ($type === 'square') {
        $token = trim((string)($provider->api_key ?? ''));
        $env = strtolower((string)($provider->environment ?? 'sandbox'));
        $base = $env === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

        if ($token === '') {
            return ['success' => false, 'message' => 'Square test failed: missing access token.'];
        }

        $ch = curl_init($base . '/v2/locations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Square-Version: 2026-01-22',
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'message' => 'Square test failed: ' . $err];
        }
        if ($http >= 200 && $http < 300) {
            return ['success' => true, 'message' => 'Square credentials are valid.'];
        }
        $msg = 'Square test failed.';
        $data = json_decode((string)$resp, true);
        if (!empty($data['errors'][0]['detail'])) {
            $msg = $data['errors'][0]['detail'];
        }
        return ['success' => false, 'message' => 'Square test failed: ' . $msg];
    }

    if ($type === 'paypal') {
        return ['success' => false, 'message' => 'PayPal test is not implemented yet.'];
    }

    return ['success' => false, 'message' => 'Unknown provider type.'];
}

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();

    $repo = new PaymentProvidersRepository();
    $repo->normalizeSingleActiveAndDefault($ownerId);
    $providersList = $repo->getAllByOwner($ownerId, 1, 50);
    $activeProvider = $repo->getActiveProviderForOwner($ownerId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "providers" => $providersList['data'] ?? [],
        "total" => $providersList['total'] ?? 0,
        "activeProvider" => $activeProvider
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getOwner();

    $repo = new PaymentProvidersRepository();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $providerType = trim($_POST['provider_type'] ?? '');
        $providerName = trim($_POST['provider_name'] ?? '');

        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $publicKey = trim($_POST['public_key'] ?? '');
        $webhookSecret = trim($_POST['webhook_secret'] ?? '');
        $environment = trim($_POST['environment'] ?? 'sandbox');
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $merchantEmail = trim($_POST['merchant_email'] ?? '') ?: null;
        $locationId = trim($_POST['location_id'] ?? '') ?: null;

        if ($providerType === '' || $providerName === '') {
            MessageUtil::setMessage("Provider type and name are required.");
            LocationUtils::reload();
        }
        if (!in_array($providerType, ['square', 'stripe', 'paypal'], true)) {
            MessageUtil::setMessage("Invalid provider type.");
            LocationUtils::reload();
        }

        $validationMessage = validateProviderFields($providerType, [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'public_key' => $publicKey,
            'location_id' => $locationId,
            'merchant_email' => $merchantEmail,
        ]);
        if ($validationMessage) {
            MessageUtil::setMessage($validationMessage);
            LocationUtils::reload();
        }
        if ($repo->providerNameExists($ownerId, $providerType, $providerName)) {
            MessageUtil::setMessage("A configuration with that name already exists for this provider type.");
            LocationUtils::reload();
        }

        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($isActive) {
            $repo->deactivateAllByOwner($ownerId);
        }

        $existing = $repo->getAllByOwner($ownerId, 1, 1);
        $isDefault = (($existing['total'] ?? 0) === 0) ? 1 : 0;

        $verificationResult = testProviderCredentials((object)[
            'provider_type' => $providerType,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'public_key' => $publicKey,
            'environment' => $environment,
            'location_id' => $locationId,
            'merchant_email' => $merchantEmail,
        ]);

        $ok = $repo->add([
            'id_owner' => $ownerId,
            'provider_type' => $providerType,
            'provider_name' => $providerName,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'public_key' => $publicKey,
            'webhook_secret' => $webhookSecret,
            'environment' => $environment,
            'currency' => $currency,
            'merchant_email' => $merchantEmail,
            'location_id' => $locationId,
            'is_active' => $isActive,
            'is_verified' => $verificationResult['success'] ? 1 : 0,
            'is_default' => $isDefault,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        MessageUtil::setMessage($ok ? "Payment provider saved. {$verificationResult['message']}" : "Failed to save payment provider.");
        LocationUtils::reload();
    }

    if ($action === 'set_active') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }
        $repo->setActive($ownerId, $providerId);
        MessageUtil::setMessage("Active payment provider updated.");
        LocationUtils::reload();
    }

    if ($action === 'set_default') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }
        $repo->setDefault($ownerId, $providerId);
        MessageUtil::setMessage("Default payment provider updated.");
        LocationUtils::reload();
    }

    if ($action === 'activate') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }
        $repo->setActive($ownerId, $providerId);
        MessageUtil::setMessage("Payment provider activated.");
        LocationUtils::reload();
    }

    if ($action === 'deactivate') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }
        // Only deactivate the selected provider; keep others untouched.
        $repo->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $providerId, 'id_owner' => $ownerId]);
        MessageUtil::setMessage("Payment provider deactivated.");
        LocationUtils::reload();
    }

    if ($action === 'edit') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }

        $providerType = trim($_POST['provider_type'] ?? '');
        $providerName = trim($_POST['provider_name'] ?? '');
        if ($providerType === '' || $providerName === '') {
            MessageUtil::setMessage("Provider type and name are required.");
            LocationUtils::reload();
        }
        if (!in_array($providerType, ['square', 'stripe', 'paypal'], true)) {
            MessageUtil::setMessage("Invalid provider type.");
            LocationUtils::reload();
        }

        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $publicKey = trim($_POST['public_key'] ?? '');
        $webhookSecret = trim($_POST['webhook_secret'] ?? '');
        $environment = trim($_POST['environment'] ?? 'sandbox');
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $merchantEmail = trim($_POST['merchant_email'] ?? '') ?: null;
        $locationId = trim($_POST['location_id'] ?? '') ?: null;

        $validationMessage = validateProviderFields($providerType, [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'public_key' => $publicKey,
            'location_id' => $locationId,
            'merchant_email' => $merchantEmail,
        ]);
        if ($validationMessage) {
            MessageUtil::setMessage($validationMessage);
            LocationUtils::reload();
        }

        $ok = $repo->update([
            'provider_type' => $providerType,
            'provider_name' => $providerName,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'public_key' => $publicKey,
            'webhook_secret' => $webhookSecret,
            'environment' => $environment,
            'currency' => $currency,
            'merchant_email' => $merchantEmail,
            'location_id' => $locationId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $providerId, 'id_owner' => $ownerId]);

        MessageUtil::setMessage($ok ? "Payment provider updated." : "Failed to update payment provider.");
        LocationUtils::reload();
    }

    if ($action === 'test') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }

        $provider = $repo->getById($providerId, $ownerId);
        if (!$provider) {
            MessageUtil::setMessage("Payment provider not found.");
            LocationUtils::reload();
        }

        $result = testProviderCredentials($provider);
        $repo->update([
            'is_verified' => $result['success'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $providerId, 'id_owner' => $ownerId]);

        MessageUtil::setMessage($result['message']);
        LocationUtils::reload();
    }

    if ($action === 'delete') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            MessageUtil::setMessage("Invalid provider id.");
            LocationUtils::reload();
        }
        // Use BaseRepository delete if available; fallback to direct query.
        if (method_exists($repo, 'delete')) {
            $repo->delete(['id' => $providerId, 'id_owner' => $ownerId]);
        } else {
            $db = new Connection();
            $db->query("DELETE FROM payment_providers_credentials WHERE id = :id AND id_owner = :owner");
            $db->bind(':id', $providerId);
            $db->bind(':owner', $ownerId);
            $db->execute();
        }
        MessageUtil::setMessage("Payment provider deleted.");
        LocationUtils::reload();
    }

    LocationUtils::reload();
});

$router->run();

