<?php

namespace App\Services;

use App\Repositories\SmtpCredentialsRepository;
use App\Utils\SiteContext;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private PHPMailer $mailer;
    private object $credentials;
    /** @var array<int,array{source:string,credentials:object}> */
    private array $credentialSets = [];

    public function __construct(?int $ownerId = null)
    {
        $ownerId = $this->resolveOwnerId($ownerId);
        $panelCredentials = (new SmtpCredentialsRepository())->getConfiguredForOwner($ownerId, SiteContext::siteKey());
        if ($panelCredentials) {
            $this->credentialSets[] = ['source' => 'panel', 'credentials' => $panelCredentials];
        }

        $envCredentials = $this->environmentCredentials();
        if ($envCredentials) {
            // Keep the pre-panel VNV transport as an emergency fallback. This is
            // intentionally second: Level 1 remains the normal source of truth,
            // while payment, password-reset and order emails retain a proven
            // delivery path if a panel credential or provider is unavailable.
            $this->credentialSets[] = ['source' => 'env', 'credentials' => $envCredentials];
        }

        if (!$this->credentialSets) {
            throw new Exception('No active SMTP provider or emergency environment SMTP transport is configured.');
        }

        $this->credentials = $this->credentialSets[0]['credentials'];
        $this->mailer = $this->createMailer(
            $this->credentials,
            (int)$this->credentials->smtp_port,
            (string)$this->credentials->smtp_encryption
        );
    }

    private function environmentCredentials(): ?object
    {
        $username = trim((string)($_ENV['MAIL_USERNAME'] ?? ''));
        $password = trim((string)($_ENV['MAIL_PASSWORD'] ?? ''));
        if ($username === '' || $password === '') return null;

        return (object)[
            'provider_name' => 'VNV environment emergency fallback',
            'smtp_host' => (string)($_ENV['MAIL_HOST'] ?? 'smtp-relay.brevo.com'),
            'smtp_port' => (int)($_ENV['MAIL_PORT'] ?? 587),
            'smtp_encryption' => strtolower((string)($_ENV['MAIL_ENCRYPTION'] ?? 'tls')),
            'smtp_username' => $username,
            'smtp_password' => $password,
            'from_email' => (string)($_ENV['MAIL_FROM_EMAIL'] ?? 'info@vnvevents.com'),
            'from_name' => (string)($_ENV['MAIL_FROM_NAME'] ?? 'VNV Events'),
            'reply_to_email' => (string)($_ENV['MAIL_REPLY_TO_EMAIL'] ?? $_ENV['MAIL_REPLY_TO'] ?? ''),
        ];
    }

    private function resolveOwnerId(?int $ownerId): int
    {
        if ($ownerId !== null && $ownerId > 0) return $ownerId;

        try {
            $session = LoginService::getSession();
            if ($session && (int)$session->getOwner() > 0) {
                return (int)$session->getOwner();
            }
        } catch (\Throwable) {
            // Public and cron requests may not have a session.
        }

        return SiteContext::businessOwnerId();
    }

    private function createMailer(object $credentials, int $port, string $encryption, bool $legacyTls = false): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string)$credentials->smtp_host;
        $mailer->SMTPAuth = true;
        $mailer->Username = (string)$credentials->smtp_username;
        $mailer->Password = (string)$credentials->smtp_password;
        $mailer->SMTPSecure = match (strtolower($encryption)) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mailer->Port = $port;
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 15;
        $mailer->SMTPKeepAlive = false;
        $mailer->SMTPDebug = 0;
        if ($legacyTls) {
            // Compatibility fallback for hosting networks with a mismatched SMTP
            // certificate chain. Strict verification is always attempted first.
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
        $mailer->setFrom((string)$credentials->from_email, (string)$credentials->from_name);
        if (!empty($credentials->reply_to_email)) {
            $mailer->addReplyTo((string)$credentials->reply_to_email);
        }
        return $mailer;
    }

    private function transportCandidates(?object $credentials = null, string $source = 'panel'): array
    {
        $credentials ??= $this->credentials;
        $port = (int)$credentials->smtp_port;
        $encryption = strtolower((string)$credentials->smtp_encryption);

        if ($source === 'env') {
            $candidates = [['port' => $port, 'encryption' => $encryption, 'legacy_tls' => true]];
            if (!($port === 465 && $encryption === 'ssl')) {
                $candidates[] = ['port' => 465, 'encryption' => 'ssl', 'legacy_tls' => true];
            }
            return $candidates;
        }

        $candidates = [['port' => $port, 'encryption' => $encryption, 'legacy_tls' => false]];

        if (!($port === 465 && $encryption === 'ssl')) {
            $candidates[] = ['port' => 465, 'encryption' => 'ssl', 'legacy_tls' => false];
        }

        if (strcasecmp((string)$credentials->smtp_host, 'smtp-relay.brevo.com') === 0
            && !($port === 2525 && $encryption === 'tls')) {
            $candidates[] = ['port' => 2525, 'encryption' => 'tls', 'legacy_tls' => false];
        }

        // Preserve the long-running VNV hosting compatibility path, but only
        // after every certificate-validating transport has failed.
        $candidates[] = ['port' => $port, 'encryption' => $encryption, 'legacy_tls' => true];

        return $candidates;
    }

    private function trySendFallback(callable $sendAction): bool
    {
        $lastException = null;
        foreach ($this->credentialSets as $set) {
            $credentials = $set['credentials'];
            foreach ($this->transportCandidates($credentials, $set['source']) as $transport) {
                $this->credentials = $credentials;
                $this->mailer = $this->createMailer(
                    $credentials,
                    $transport['port'],
                    $transport['encryption'],
                    (bool)$transport['legacy_tls']
                );
                try {
                    return $sendAction();
                } catch (\Throwable $exception) {
                    $lastException = $exception;
                    try { $this->mailer->smtpClose(); } catch (\Throwable) {}
                }
            }
        }

        throw new Exception($this->mailer->ErrorInfo ?: ($lastException?->getMessage() ?? 'SMTP delivery failed.'));
    }

    public function sendSimpleEmail(string $to, string $subject, string $body, bool $isHTML = true): bool
    {
        return $this->trySendFallback(function () use ($to, $subject, $body, $isHTML) {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();

            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $body;
            $this->mailer->isHTML($isHTML);

            if (!$this->mailer->send()) {
                throw new Exception($this->mailer->ErrorInfo);
            }

            return true;
        });
    }

    public function sendTemplateEmail(string $to, string $subject, string $templatePath, array $data = []): bool
    {
        try {
            $body = $this->renderTemplate($templatePath, $data);
            return $this->sendSimpleEmail($to, $subject, $body, true);
        } catch (Exception $e) {
            throw new Exception("Template email failed for $to: " . $e->getMessage());
        }
    }

    public function sendEmailWithAttachment(string $to, string $subject, string $body, array $attachments = [], bool $isHTML = true): bool
    {
        return $this->trySendFallback(function () use ($to, $subject, $body, $attachments, $isHTML) {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();

            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $body;
            $this->mailer->isHTML($isHTML);

            foreach ($attachments as $attachment) {
                if (isset($attachment['path']) && file_exists($attachment['path'])) {
                    $this->mailer->addAttachment(
                        $attachment['path'],
                        $attachment['name'] ?? basename($attachment['path'])
                    );
                }
            }

            if (!$this->mailer->send()) {
                throw new Exception($this->mailer->ErrorInfo);
            }

            return true;
        });
    }

    public function sendEmailWithStringAttachment(string $to, string $subject, string $body, string $content, string $filename, string $mime = 'application/pdf'): bool
    {
        return $this->trySendFallback(function () use ($to, $subject, $body, $content, $filename, $mime) {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->addStringAttachment($content, $filename, 'base64', $mime);
            return $this->mailer->send();
        });
    }

    public function sendBulkEmail(array $recipients, string $subject, string $body, bool $isHTML = true): array
    {
        $results = [];

        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? $recipient['email'] : $recipient;
            $name  = is_array($recipient) ? ($recipient['name'] ?? '') : '';

            try {
                $results[$email] = $this->trySendFallback(function () use ($email, $name, $subject, $body, $isHTML) {
                    $this->mailer->clearAddresses();
                    $this->mailer->clearAttachments();
                    $this->mailer->addAddress($email, $name);
                    $this->mailer->Subject = $subject;
                    $this->mailer->Body = $body;
                    $this->mailer->isHTML($isHTML);
                    return $this->mailer->send();
                });
            } catch (\Throwable $e) {
                $results[$email] = false;
            }
        }

        return $results;
    }

    private function renderTemplate(string $templatePath, array $data = []): string
    {
        if (!file_exists($templatePath)) {
            throw new Exception("Template not found: $templatePath");
        }

        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    public function getDebugInfo(): string
    {
        return $this->mailer->ErrorInfo;
    }

    public function testSMTPConnection(): bool
    {
        foreach ($this->credentialSets as $set) {
            $credentials = $set['credentials'];
            foreach ($this->transportCandidates($credentials, $set['source']) as $transport) {
                $this->credentials = $credentials;
                $this->mailer = $this->createMailer(
                    $credentials,
                    $transport['port'],
                    $transport['encryption'],
                    (bool)$transport['legacy_tls']
                );
                try {
                    if ($this->mailer->smtpConnect()) {
                        $this->mailer->smtpClose();
                        return true;
                    }
                } catch (\Throwable) {
                    try { $this->mailer->smtpClose(); } catch (\Throwable) {}
                }
            }
        }
        return false;
    }
}
