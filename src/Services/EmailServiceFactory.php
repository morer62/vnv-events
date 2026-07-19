<?php

namespace App\Services;

use App\Repositories\SmtpCredentialsRepository;
use App\Utils\SiteContext;
use PHPMailer\PHPMailer\PHPMailer;

class EmailServiceFactory
{
    public static function sendWithOwnerProvider(int $ownerId, string $toEmail, string $subject, string $body, bool $isHtml = true): array
    {
        try {
            $repo = new SmtpCredentialsRepository();
            $selected = $repo->getConfiguredForOwner($ownerId, SiteContext::siteKey());

            if (!$selected) {
                $fallback = new EmailService();
                $ok = $fallback->sendSimpleEmail($toEmail, $subject, $body, $isHtml);
                return ['success' => $ok, 'message' => $ok ? 'Sent with fallback provider.' : 'Fallback email failed.', 'used_fallback' => 1];
            }

            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = (string)$selected->smtp_host;
            $mailer->Port = (int)$selected->smtp_port;
            $mailer->SMTPAuth = true;
            $mailer->Username = (string)$selected->smtp_username;
            $mailer->Password = (string)$selected->smtp_password;
            $mailer->SMTPSecure = match ((string)$selected->smtp_encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS
            };
            $mailer->CharSet = 'UTF-8';
            $mailer->Timeout = 15;
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            $mailer->setFrom((string)$selected->from_email, (string)$selected->from_name);
            if (!empty($selected->reply_to_email)) {
                $mailer->addReplyTo((string)$selected->reply_to_email);
            }
            $mailer->addAddress($toEmail);
            $mailer->Subject = $subject;
            $mailer->isHTML($isHtml);
            $mailer->Body = $body;
            $mailer->send();

            return ['success' => true, 'message' => 'Email sent using configured SMTP provider.', 'used_fallback' => 0];
        } catch (\Throwable $e) {
            try {
                $fallback = new EmailService();
                $ok = $fallback->sendSimpleEmail($toEmail, $subject, $body, $isHtml);
                return ['success' => $ok, 'message' => $ok ? 'Sent with fallback provider after SMTP failure.' : ('SMTP and fallback failed: ' . $e->getMessage()), 'used_fallback' => 1];
            } catch (\Throwable $e2) {
                return ['success' => false, 'message' => 'Email send failed: ' . $e2->getMessage(), 'used_fallback' => 1];
            }
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
        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = (string)($credentials['smtp_host'] ?? '');
            $mailer->Port = (int)($credentials['smtp_port'] ?? 587);
            $mailer->SMTPAuth = true;
            $mailer->Username = (string)($credentials['smtp_username'] ?? '');
            $mailer->Password = (string)($credentials['smtp_password'] ?? '');
            $mailer->SMTPSecure = match ((string)($credentials['smtp_encryption'] ?? 'tls')) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS
            };
            $mailer->Timeout = 10;
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            $ok = $mailer->smtpConnect();
            if ($ok) {
                $mailer->smtpClose();
                return ['success' => true, 'message' => 'SMTP connection successful.'];
            }
            return ['success' => false, 'message' => 'SMTP connection failed: ' . $mailer->ErrorInfo];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'SMTP test failed: ' . $e->getMessage()];
        }
    }

    public static function sendTestEmail(int $ownerId, int $smtpId, string $testEmail): array
    {
        try {
            $repo = new SmtpCredentialsRepository();
            $smtp = $repo->getById($smtpId, $ownerId);
            if (!$smtp || !(int)$smtp->is_active) {
                return ['success' => false, 'message' => 'SMTP configuration not found or inactive.'];
            }

            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = (string)$smtp->smtp_host;
            $mailer->Port = (int)$smtp->smtp_port;
            $mailer->SMTPAuth = true;
            $mailer->Username = (string)$smtp->smtp_username;
            $mailer->Password = (string)$smtp->smtp_password;
            $mailer->SMTPSecure = match ((string)$smtp->smtp_encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS
            };
            $mailer->CharSet = 'UTF-8';
            $mailer->Timeout = 15;
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            $fromEmail = (string)($smtp->from_email ?? '');
            $fromName = (string)($smtp->from_name ?? 'VNV Events');
            if ($fromEmail === '') {
                return ['success' => false, 'message' => 'From email is missing in SMTP config.'];
            }

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
            return ['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()];
        }
    }
}
