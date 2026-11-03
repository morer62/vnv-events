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
    private string $fromEmail;
    private string $fromName;

    public function __construct(?int $ownerId = null)
    {
        $ownerId = $this->resolveOwnerId($ownerId);
        $credentials = (new SmtpCredentialsRepository())->getConfiguredForOwner($ownerId, SiteContext::siteKey());
        if (!$credentials) {
            throw new Exception('No active SMTP provider is configured for this account and site.');
        }

        $this->credentials = $credentials;
        $this->fromEmail = (string)$credentials->from_email;
        $this->fromName = (string)$credentials->from_name;
        $this->mailer = $this->createMailer(
            (int)$credentials->smtp_port,
            (string)$credentials->smtp_encryption
        );
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

    private function createMailer(int $port, string $encryption, bool $legacyTls = false): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string)$this->credentials->smtp_host;
        $mailer->SMTPAuth = true;
        $mailer->Username = (string)$this->credentials->smtp_username;
        $mailer->Password = (string)$this->credentials->smtp_password;
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
        $mailer->setFrom($this->fromEmail, $this->fromName);
        if (!empty($this->credentials->reply_to_email)) {
            $mailer->addReplyTo((string)$this->credentials->reply_to_email);
        }
        return $mailer;
    }

    private function transportCandidates(): array
    {
        $port = (int)$this->credentials->smtp_port;
        $encryption = strtolower((string)$this->credentials->smtp_encryption);
        $candidates = [['port' => $port, 'encryption' => $encryption, 'legacy_tls' => false]];

        if (!($port === 465 && $encryption === 'ssl')) {
            $candidates[] = ['port' => 465, 'encryption' => 'ssl', 'legacy_tls' => false];
        }

        if (strcasecmp((string)$this->credentials->smtp_host, 'smtp-relay.brevo.com') === 0
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
        foreach ($this->transportCandidates() as $transport) {
            $this->mailer = $this->createMailer(
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
        foreach ($this->transportCandidates() as $transport) {
            $this->mailer = $this->createMailer(
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
        return false;
    }
}
