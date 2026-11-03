<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();

use App\Repositories\SmtpCredentialsRepository;
use App\Services\EmailService;
use App\Services\EmailServiceFactory;
use App\Utils\SiteContext;

$ownerId = SiteContext::businessOwnerId();
$repo = new SmtpCredentialsRepository();
$selected = $repo->getConfiguredForOwner($ownerId, SiteContext::siteKey());
$fixtureId = 0;

if (!$selected) {
    $repo->add([
        'id_owner' => $ownerId,
        'site_key' => SiteContext::siteKey(),
        'provider_name' => 'Automated SMTP panel-source test',
        'provider_type' => 'custom',
        'smtp_host' => 'smtp.panel.test',
        'smtp_port' => 2525,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'panel-user',
        'smtp_password' => 'panel-secret',
        'from_email' => 'panel@example.test',
        'from_name' => 'Panel Test',
        'reply_to_email' => 'reply@example.test',
        'is_active' => 1,
        'is_verified' => 0,
        'is_default' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $selected = $repo->getConfiguredForOwner($ownerId, SiteContext::siteKey());
    $fixtureId = (int)($selected->id ?? 0);
}

if (!$selected) throw new RuntimeException('Test SMTP panel provider could not be prepared.');

$_ENV['MAIL_HOST'] = 'env-credentials-must-not-be-used.invalid';
$_ENV['MAIL_USERNAME'] = 'env-user-must-not-be-used';
$_ENV['MAIL_PASSWORD'] = 'env-password-must-not-be-used';
$_ENV['MAIL_REPLY_TO'] = 'legacy-reply@example.test';
unset($_ENV['MAIL_REPLY_TO_EMAIL']);

try {
    $service = new EmailService($ownerId);
    $property = new ReflectionProperty(EmailService::class, 'mailer');
    $property->setAccessible(true);
    $mailer = $property->getValue($service);

    if ($mailer->Host !== (string)$selected->smtp_host) throw new RuntimeException('SMTP host did not come from the panel provider.');
    if ($mailer->Port !== (int)$selected->smtp_port) throw new RuntimeException('SMTP port did not come from the panel provider.');
    if ($mailer->Username !== (string)$selected->smtp_username) throw new RuntimeException('SMTP username did not come from the panel provider.');
    if ($mailer->Password !== (string)$selected->smtp_password) throw new RuntimeException('SMTP password did not come from the encrypted panel provider.');
    if ($mailer->Host === $_ENV['MAIL_HOST'] || $mailer->Username === $_ENV['MAIL_USERNAME']) {
        throw new RuntimeException('EmailService did not keep the panel provider as the primary transport.');
    }

    $setsProperty = new ReflectionProperty(EmailService::class, 'credentialSets');
    $setsProperty->setAccessible(true);
    $sets = $setsProperty->getValue($service);
    if (($sets[0]['source'] ?? '') !== 'panel' || ($sets[1]['source'] ?? '') !== 'env') {
        throw new RuntimeException('SMTP failover must try the panel provider before the environment emergency fallback.');
    }
    if (($sets[1]['credentials']->reply_to_email ?? '') !== 'legacy-reply@example.test') {
        throw new RuntimeException('The environment fallback must preserve the historical MAIL_REPLY_TO setting.');
    }

    $transportMethod = new ReflectionMethod(EmailService::class, 'transportCandidates');
    $transportMethod->setAccessible(true);
    $candidates = $transportMethod->invoke($service);
    if (($candidates[0]['legacy_tls'] ?? true) !== false) {
        throw new RuntimeException('The first SMTP transport must validate TLS certificates.');
    }
    if (($candidates[array_key_last($candidates)]['legacy_tls'] ?? false) !== true) {
        throw new RuntimeException('Legacy TLS compatibility must be the final transport only.');
    }

    $envCandidates = $transportMethod->invoke($service, $sets[1]['credentials'], 'env');
    if (($envCandidates[0]['legacy_tls'] ?? false) !== true) {
        throw new RuntimeException('The emergency environment transport must preserve the historical VNV TLS compatibility behavior.');
    }

    $factoryTransportMethod = new ReflectionMethod(EmailServiceFactory::class, 'transportCandidates');
    $factoryTransportMethod->setAccessible(true);
    $factoryCandidates = $factoryTransportMethod->invoke(null, 'smtp-relay.brevo.com', 587, 'tls');
    if (($factoryCandidates[0]['legacy_tls'] ?? true) !== false
        || ($factoryCandidates[array_key_last($factoryCandidates)]['legacy_tls'] ?? false) !== true) {
        throw new RuntimeException('SMTP panel tests must use the same strict-first transport order.');
    }

    echo "PASS encrypted panel credentials remain the primary SMTP transport\n";
    echo "PASS historical environment SMTP remains available only as emergency fallback\n";
    echo "PASS historical environment reply-to configuration remains compatible\n";
    echo "PASS strict TLS is attempted before the final compatibility fallback\n";
    echo "PASS panel connection tests use the same strict-first transport order\n";
} finally {
    if ($fixtureId > 0) $repo->deleteSmtp($fixtureId, $ownerId);
}
