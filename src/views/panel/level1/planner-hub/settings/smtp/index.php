<?php

use App\Repositories\SmtpCredentialsRepository;
use App\Services\EmailServiceFactory;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getId(); // level 1 only

    $repo = new SmtpCredentialsRepository();
    $smtpList = $repo->getAllByOwner($ownerId, 1, 100);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "smtpConfigs" => $smtpList['data'],
        "total" => $smtpList['total'],
        "availableProviders" => EmailServiceFactory::getAvailableProviders()
    ]);
});

$router->post(function () {
    $session = LoginService::getSession();
    $ownerId = (int)$session->getId(); // level 1 only
    $repo = new SmtpCredentialsRepository();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $providerName = trim($_POST['provider_name'] ?? '');
        $providerType = trim($_POST['provider_type'] ?? 'custom');
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? 'tls');
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = (string)($_POST['smtp_password'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName = trim($_POST['from_name'] ?? '');
        $replyToEmail = trim($_POST['reply_to_email'] ?? '') ?: null;

        if ($providerName === '' || $smtpHost === '' || $smtpUsername === '' || $smtpPassword === '' || $fromEmail === '' || $fromName === '') {
            MessageUtil::setMessage("All required fields must be filled.", "Error", "error");
            LocationUtils::reload();
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            MessageUtil::setMessage("Invalid from email.", "Error", "error");
            LocationUtils::reload();
        }
        if ($replyToEmail && !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            MessageUtil::setMessage("Invalid reply-to email.", "Error", "error");
            LocationUtils::reload();
        }
        if ($repo->providerNameExists($ownerId, $providerName)) {
            MessageUtil::setMessage("A configuration with this name already exists.", "Error", "error");
            LocationUtils::reload();
        }

        $test = EmailServiceFactory::testSmtpConnection([
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'smtp_password' => $smtpPassword
        ]);
        $isVerified = $test['success'] ? 1 : 0;
        $existing = $repo->getAllByOwner($ownerId, 1, 1);
        $isDefault = ((int)($existing['total'] ?? 0) === 0) ? 1 : 0;

        $ok = $repo->add([
            'id_owner' => $ownerId,
            'provider_name' => $providerName,
            'provider_type' => $providerType,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'smtp_password' => $smtpPassword,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to_email' => $replyToEmail,
            'is_active' => 1,
            'is_verified' => $isVerified,
            'is_default' => $isDefault,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            MessageUtil::setMessage($isVerified ? "SMTP configuration saved and verified." : "SMTP saved, but verification failed. Check credentials.", $isVerified ? "Success" : "Warning", $isVerified ? "success" : "warning");
        } else {
            MessageUtil::setMessage("Failed to save SMTP configuration.", "Error", "error");
        }
        LocationUtils::reload();
    }

    if ($action === 'send_test') {
        $smtpId = (int)($_POST['smtp_id'] ?? 0);
        $testEmail = trim($_POST['test_email'] ?? '');
        if ($smtpId <= 0 || $testEmail === '') {
            MessageUtil::setMessage("SMTP and test email are required.", "Error", "error");
            LocationUtils::reload();
        }
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            MessageUtil::setMessage("Invalid email address.", "Error", "error");
            LocationUtils::reload();
        }
        $result = EmailServiceFactory::sendTestEmail($ownerId, $smtpId, $testEmail);
        MessageUtil::setMessage($result['success'] ? $result['message'] : $result['message'], $result['success'] ? "Success" : "Error", $result['success'] ? "success" : "error");
        LocationUtils::reload();
    }

    if ($action === 'set_default') {
        $smtpId = (int)($_POST['smtp_id'] ?? 0);
        $ok = $repo->setAsDefault($smtpId, $ownerId);
        MessageUtil::setMessage($ok ? "Default SMTP updated." : "Failed to update default SMTP.", $ok ? "Success" : "Error", $ok ? "success" : "error");
        LocationUtils::reload();
    }

    if ($action === 'activate') {
        $smtpId = (int)($_POST['smtp_id'] ?? 0);
        $ok = $repo->activate($smtpId, $ownerId);
        MessageUtil::setMessage($ok ? "SMTP activated." : "Failed to activate SMTP.", $ok ? "Success" : "Error", $ok ? "success" : "error");
        LocationUtils::reload();
    }

    if ($action === 'deactivate') {
        $smtpId = (int)($_POST['smtp_id'] ?? 0);
        $ok = $repo->deactivate($smtpId, $ownerId);
        MessageUtil::setMessage($ok ? "SMTP deactivated." : "Failed to deactivate SMTP.", $ok ? "Success" : "Error", $ok ? "success" : "error");
        LocationUtils::reload();
    }

    if ($action === 'delete') {
        $smtpId = (int)($_POST['smtp_id'] ?? 0);
        $ok = $repo->deleteSmtp($smtpId, $ownerId);
        MessageUtil::setMessage($ok ? "SMTP deleted." : "Failed to delete SMTP.", $ok ? "Success" : "Error", $ok ? "success" : "error");
        LocationUtils::reload();
    }

    if ($action === 'update') {
        $smtpId = (int)($_POST['smtp_id'] ?? 0);
        $providerName = trim($_POST['provider_name'] ?? '');
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? 'tls');
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = (string)($_POST['smtp_password'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName = trim($_POST['from_name'] ?? '');
        $replyToEmail = trim($_POST['reply_to_email'] ?? '') ?: null;

        if ($smtpId <= 0 || $providerName === '' || $smtpHost === '' || $smtpUsername === '' || $fromEmail === '' || $fromName === '') {
            MessageUtil::setMessage("All required fields must be filled.", "Error", "error");
            LocationUtils::reload();
        }
        if ($repo->providerNameExists($ownerId, $providerName, $smtpId)) {
            MessageUtil::setMessage("Another configuration with this name already exists.", "Error", "error");
            LocationUtils::reload();
        }

        $data = [
            'provider_name' => $providerName,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to_email' => $replyToEmail,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($smtpPassword !== '') {
            $data['smtp_password'] = $smtpPassword;
            $data['is_verified'] = 0;
        }
        $ok = $repo->update($data, ['id' => $smtpId, 'id_owner' => $ownerId]);
        MessageUtil::setMessage($ok ? "SMTP updated." : "Failed to update SMTP.", $ok ? "Success" : "Error", $ok ? "success" : "error");
        LocationUtils::reload();
    }
});

$router->run();

