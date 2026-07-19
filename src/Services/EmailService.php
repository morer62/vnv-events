<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private PHPMailer $mailer;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->fromEmail = (string)($_ENV['MAIL_FROM_EMAIL'] ?? 'info@vnvevents.com');
        $this->fromName  = (string)($_ENV['MAIL_FROM_NAME'] ?? 'VNV Events');

        $this->configureSMTP();
    }

    private function configureSMTP(): void
    {
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host       = (string)($_ENV['MAIL_HOST'] ?? 'smtp-relay.brevo.com');
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = (string)($_ENV['MAIL_USERNAME'] ?? '');
            $this->mailer->Password   = (string)($_ENV['MAIL_PASSWORD'] ?? '');
            $this->mailer->SMTPSecure = match (strtolower((string)($_ENV['MAIL_ENCRYPTION'] ?? 'tls'))) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS
            };
            $this->mailer->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
            $this->mailer->CharSet    = 'UTF-8';
            $this->mailer->Timeout    = 15;
            $this->mailer->SMTPKeepAlive = false;
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ];
            $this->mailer->SMTPDebug   = 0;
            $this->mailer->setFrom($this->fromEmail, $this->fromName);

        } catch (Exception $e) {
            throw new Exception("Failed to configure email service: " . $e->getMessage());
        }
    }

    private function trySendFallback(callable $sendAction): bool
    {
        try {
            return $sendAction();
        } catch (Exception $e) {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $this->mailer->Port = 465;
            $this->mailer->Timeout = 15;
            try {
                return $sendAction();
            } catch (Exception $ex) {
                throw new Exception($this->mailer->ErrorInfo ?: $ex->getMessage());
            }
        }
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

    public function sendBulkEmail(array $recipients, string $subject, string $body, bool $isHTML = true): array
    {
        $results = [];

        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? $recipient['email'] : $recipient;
            $name  = is_array($recipient) ? ($recipient['name'] ?? '') : '';

            try {
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();

                $this->mailer->addAddress($email, $name);
                $this->mailer->Subject = $subject;
                $this->mailer->Body    = $body;
                $this->mailer->isHTML($isHTML);

                if (!$this->mailer->send()) {
                    throw new Exception($this->mailer->ErrorInfo);
                }

                $results[$email] = true;
            } catch (Exception $e) {
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
        try {
            return $this->mailer->smtpConnect([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ]);
        } catch (Exception $e) {
            return false;
        }
    }
}
