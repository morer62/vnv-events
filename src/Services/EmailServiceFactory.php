<?php

namespace App\Services;

use App\Repositories\SmtpCredentialsRepository;
use App\Utils\SiteContext;
use PHPMailer\PHPMailer\PHPMailer;

class EmailServiceFactory
{
    private static function transportCandidates(string $host, int $port, string $encryption): array
    {
        $encryption = strtolower($encryption);
        $candidates = [['port' => $port, 'encryption' => $encryption, 'legacy_tls' => false]];

        if (!($port === 465 && $encryption === 'ssl')) {
            $candidates[] = ['port' => 465, 'encryption' => 'ssl', 'legacy_tls' => false];
        }

        if (strcasecmp($host, 'smtp-relay.brevo.com') === 0
            && !($port === 2525 && $encryption === 'tls')) {
            $candidates[] = ['port' => 2525, 'encryption' => 'tls', 'legacy_tls' => false];
        }

        $candidates[] = ['port' => $port, 'encryption' => $encryption, 'legacy_tls' => true];
        return $candidates;
    }

    private static function buildMailer(object $smtp, array $transport, int $timeout = 15): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string)$smtp->smtp_host;
        $mailer->Port = (int)$transport['port'];
        $mailer->SMTPAuth = true;
        $mailer->Username = (string)$smtp->smtp_username;
        $mailer->Password = (string)$smtp->smtp_password;
        $mailer->SMTPSecure = match ((string)$transport['encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = $timeout;
        if ((bool)$transport['legacy_tls']) {
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
        return $mailer;
    }

    public static function sendWithOwnerProviderAttachment(int $ownerId, string $toEmail, string $subject, string $body, string $attachmentContent, string $attachmentName): array
    {
        try {
            $service = new EmailService($ownerId);
            $service->sendEmailWithStringAttachment($toEmail, $subject, $body, $attachmentContent, $attachmentName);
            return ['success'=>true,'message'=>'Invoice emailed with PDF attachment.','used_fallback'=>0];
        } catch (\Throwable $e) { return ['success'=>false,'message'=>'Invoice email failed: '.$e->getMessage(),'used_fallback'=>0]; }
    }

    public static function sendWithOwnerProvider(int $ownerId, string $toEmail, string $subject, string $body, bool $isHtml = true): array
    {
        try {
            $service = new EmailService($ownerId);
            $ok = $service->sendSimpleEmail($toEmail, $subject, $body, $isHtml);
            return ['success' => $ok, 'message' => $ok ? 'Email sent using configured SMTP provider.' : 'Configured SMTP provider failed.', 'used_fallback' => 0];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Email send failed: ' . $e->getMessage(), 'used_fallback' => 0];
        }
    }

    public static function getAvailableProviders(): array
    {
        return [
            'gmail' => 'Gmail',
            'sendgrid' => 'SendGrid',
            'mailgun' => 'Mailgun',
            'aws_ses' => 'AWS SES',
            'brevo' => 'Brevo (Sendinblue)',
            'outlook' => 'Outlook / Office 365',
            'custom' => 'Custom SMTP Server'
        ];
    }

    public static function testSmtpConnection(array $credentials): array
    {
        $smtp = (object)$credentials;
        $host = (string)($smtp->smtp_host ?? '');
        $lastError = 'SMTP connection failed.';

        foreach (self::transportCandidates(
            $host,
            (int)($smtp->smtp_port ?? 587),
            (string)($smtp->smtp_encryption ?? 'tls')
        ) as $transport) {
            $mailer = null;
            try {
                $mailer = self::buildMailer($smtp, $transport, 10);
                if ($mailer->smtpConnect()) {
                    $mailer->smtpClose();
                    return ['success' => true, 'message' => 'SMTP connection successful.'];
                }
                $lastError = $mailer->ErrorInfo ?: $lastError;
            } catch (\Throwable $e) {
                $lastError = $mailer?->ErrorInfo ?: $e->getMessage();
            } finally {
                try { $mailer?->smtpClose(); } catch (\Throwable) {}
            }
        }

        return ['success' => false, 'message' => 'SMTP test failed: ' . $lastError];
    }

    public static function sendTestEmail(int $ownerId, int $smtpId, string $testEmail): array
    {
        try {
            $repo = new SmtpCredentialsRepository();
            $smtp = $repo->getById($smtpId, $ownerId);
            if (!$smtp || !(int)$smtp->is_active) {
                return ['success' => false, 'message' => 'SMTP configuration not found or inactive.'];
            }

            $fromEmail = (string)($smtp->from_email ?? '');
            $fromName = (string)($smtp->from_name ?? 'VNV Events');
            if ($fromEmail === '') {
                return ['success' => false, 'message' => 'From email is missing in SMTP config.'];
            }

            $lastError = 'SMTP delivery failed.';
            foreach (self::transportCandidates(
                (string)$smtp->smtp_host,
                (int)$smtp->smtp_port,
                (string)$smtp->smtp_encryption
            ) as $transport) {
                $mailer = null;
                try {
                    $mailer = self::buildMailer($smtp, $transport);
                    $mailer->setFrom($fromEmail, $fromName);
                    if (!empty($smtp->reply_to_email)) {
                        $mailer->addReplyTo((string)$smtp->reply_to_email);
                    }
                    $mailer->addAddress($testEmail);
                    $mailer->Subject = 'SMTP Test Email';
                    $mailer->isHTML(true);
                    $mailer->Body = '<h3>SMTP configuration test</h3><p>This confirms your SMTP settings are working.</p>';
                    $mailer->send();
                    $repo->markAsVerified($smtpId, $ownerId);
                    return ['success' => true, 'message' => 'Test email sent successfully to ' . $testEmail];
                } catch (\Throwable $e) {
                    $lastError = $mailer?->ErrorInfo ?: $e->getMessage();
                } finally {
                    try { $mailer?->smtpClose(); } catch (\Throwable) {}
                }
            }

            return ['success' => false, 'message' => 'Failed to send test email: ' . $lastError];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()];
        }
    }
}
