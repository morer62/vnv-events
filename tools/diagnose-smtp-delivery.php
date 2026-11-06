<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();

use App\Repositories\SmtpCredentialsRepository;
use App\Services\EmailService;
use App\Utils\SiteContext;
use PHPMailer\PHPMailer\PHPMailer;

$mode = $argv[1] ?? 'status';
$recipient = $argv[2] ?? '';
$ownerId = SiteContext::businessOwnerId();
$siteKey = SiteContext::siteKey();
$repository = new SmtpCredentialsRepository();
$provider = $repository->getConfiguredForOwner($ownerId, $siteKey);

function yesNo(bool $value): string
{
    return $value ? 'yes' : 'no';
}

function envMailer(int $port, string $encryption): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) ($_ENV['MAIL_HOST'] ?? 'smtp-relay.brevo.com');
    $mail->SMTPAuth = true;
    $mail->Username = (string) ($_ENV['MAIL_USERNAME'] ?? '');
    $mail->Password = (string) ($_ENV['MAIL_PASSWORD'] ?? '');
    $mail->SMTPSecure = match (strtolower($encryption)) {
        'ssl' => PHPMailer::ENCRYPTION_SMTPS,
        'none' => '',
        default => PHPMailer::ENCRYPTION_STARTTLS,
    };
    $mail->Port = $port;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 20;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];
    $mail->setFrom(
        (string) ($_ENV['MAIL_FROM_EMAIL'] ?? 'info@vnvevents.com'),
        (string) ($_ENV['MAIL_FROM_NAME'] ?? 'VNV Events')
    );
    return $mail;
}

if ($mode === 'status') {
    echo 'site=' . $siteKey . PHP_EOL;
    echo 'owner=' . $ownerId . PHP_EOL;
    echo 'env_host=' . (string) ($_ENV['MAIL_HOST'] ?? '') . PHP_EOL;
    echo 'env_port=' . (string) ($_ENV['MAIL_PORT'] ?? '') . PHP_EOL;
    echo 'env_encryption=' . (string) ($_ENV['MAIL_ENCRYPTION'] ?? '') . PHP_EOL;
    echo 'env_username_configured=' . yesNo(trim((string) ($_ENV['MAIL_USERNAME'] ?? '')) !== '') . PHP_EOL;
    echo 'env_password_configured=' . yesNo(trim((string) ($_ENV['MAIL_PASSWORD'] ?? '')) !== '') . PHP_EOL;
    echo 'env_from=' . (string) ($_ENV['MAIL_FROM_EMAIL'] ?? '') . PHP_EOL;
    echo 'panel_provider=' . ($provider ? (string) $provider->provider_name : 'none') . PHP_EOL;
    if ($provider) {
        echo 'panel_id=' . (int) $provider->id . PHP_EOL;
        echo 'panel_host=' . (string) $provider->smtp_host . PHP_EOL;
        echo 'panel_port=' . (int) $provider->smtp_port . PHP_EOL;
        echo 'panel_encryption=' . (string) $provider->smtp_encryption . PHP_EOL;
        echo 'panel_username_configured=' . yesNo(trim((string) $provider->smtp_username) !== '') . PHP_EOL;
        echo 'panel_password_configured=' . yesNo(trim((string) $provider->smtp_password) !== '') . PHP_EOL;
        echo 'panel_from=' . (string) $provider->from_email . PHP_EOL;
        echo 'panel_active=' . yesNo((int) $provider->is_active === 1) . PHP_EOL;
        echo 'panel_verified=' . yesNo((int) $provider->is_verified === 1) . PHP_EOL;
        echo 'panel_last_error=' . trim((string) ($provider->last_error ?? '')) . PHP_EOL;
    }
    exit(0);
}

if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid recipient is required.\n");
    exit(2);
}

if ($mode === 'send-env') {
    $transports = [
        [(int) ($_ENV['MAIL_PORT'] ?? 587), (string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')],
        [465, 'ssl'],
    ];
    $lastError = 'No transport attempted.';
    foreach ($transports as [$port, $encryption]) {
        $mail = null;
        try {
            $mail = envMailer($port, $encryption);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = 'VNV Events local SMTP baseline test';
            $mail->Body = '<p>This confirms the historical VNV Events environment SMTP transport is working.</p>';
            $mail->AltBody = 'This confirms the historical VNV Events environment SMTP transport is working.';
            $mail->send();
            echo "PASS env SMTP delivery via port {$port}.\n";
            exit(0);
        } catch (Throwable $exception) {
            $lastError = $mail?->ErrorInfo ?: $exception->getMessage();
            try { $mail?->smtpClose(); } catch (Throwable) {}
        }
    }
    fwrite(STDERR, 'FAIL env SMTP delivery: ' . $lastError . PHP_EOL);
    exit(1);
}

if ($mode === 'send-panel') {
    try {
        (new EmailService($ownerId))->sendSimpleEmail(
            $recipient,
            'VNV Events local panel SMTP test',
            '<p>This confirms the VNV Events Level 1 SMTP provider is working.</p>',
            true
        );
        echo "PASS panel SMTP delivery.\n";
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL panel SMTP delivery: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($mode === 'send-payment-template') {
    try {
        (new EmailService($ownerId))->sendTemplateEmail(
            $recipient,
            'VNV Events payment confirmation template test (no charge)',
            dirname(__DIR__) . '/src/views/templates/emails/payment_confirmation.php',
            [
                'orderId' => 'TEST',
                'paymentType' => 'SMTP template test — no charge',
                'amount' => 0,
                'eventDate' => 'Test only',
                'eventTime' => 'No live event',
                'location' => 'No live order was created',
                'orderUrl' => 'https://vnvevents.com/',
                'remainingMessage' => 'This message only verifies the payment-confirmation email template and SMTP delivery. No payment was processed.',
            ]
        );
        echo "PASS payment template delivery.\n";
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL payment template delivery: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

fwrite(STDERR, "Unknown mode. Use status, send-env, send-panel or send-payment-template.\n");
exit(2);
