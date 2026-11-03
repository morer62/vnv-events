<?php

declare(strict_types=1);

use App\Repositories\SmtpCredentialsRepository;
use App\Utils\SiteContext;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$required = [
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_ENCRYPTION',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_FROM_EMAIL',
    'MAIL_FROM_NAME',
];

foreach ($required as $key) {
    if (trim((string)($_ENV[$key] ?? '')) === '') {
        fwrite(STDERR, "Migration stopped: {$key} is missing.\n");
        exit(1);
    }
}

$ownerId = SiteContext::businessOwnerId();
$siteKey = SiteContext::siteKey();
$smtpHost = strtolower(trim((string)$_ENV['MAIL_HOST']));
$providerType = match (true) {
    str_contains($smtpHost, 'brevo.com'),
    str_contains($smtpHost, 'sendinblue.com') => 'brevo',
    str_contains($smtpHost, 'amazonaws.com') => 'aws_ses',
    str_contains($smtpHost, 'sendgrid.net') => 'sendgrid',
    str_contains($smtpHost, 'mailgun.org') => 'mailgun',
    str_contains($smtpHost, 'gmail.com') => 'gmail',
    str_contains($smtpHost, 'office365.com'),
    str_contains($smtpHost, 'outlook.com') => 'outlook',
    default => 'custom',
};
$providerLabel = $providerType === 'brevo' ? 'VNV Events Brevo Production' : 'VNV Events Production';
$repository = new SmtpCredentialsRepository();
$existing = $repository->getConfiguredForOwner($ownerId, $siteKey);

if ($existing) {
    echo "SMTP panel provider already configured for {$siteKey}; no migration was needed.\n";
    exit(0);
}

$created = $repository->add([
    'id_owner' => $ownerId,
    'site_key' => $siteKey,
    'provider_name' => $providerLabel,
    'provider_type' => $providerType,
    'smtp_host' => $smtpHost,
    'smtp_port' => (int)$_ENV['MAIL_PORT'],
    'smtp_encryption' => strtolower(trim((string)$_ENV['MAIL_ENCRYPTION'])),
    'smtp_username' => (string)$_ENV['MAIL_USERNAME'],
    'smtp_password' => (string)$_ENV['MAIL_PASSWORD'],
    'from_email' => trim((string)$_ENV['MAIL_FROM_EMAIL']),
    'from_name' => trim((string)$_ENV['MAIL_FROM_NAME']),
    'reply_to_email' => trim((string)($_ENV['MAIL_REPLY_TO'] ?? $_ENV['MAIL_FROM_EMAIL'])),
    'is_active' => 1,
    'is_verified' => 0,
    'is_default' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

if (!$created || !$repository->getConfiguredForOwner($ownerId, $siteKey)) {
    fwrite(STDERR, "Migration failed: the SMTP panel provider was not created.\n");
    exit(1);
}

echo "SMTP credentials were copied into the encrypted panel provider for {$siteKey}.\n";
