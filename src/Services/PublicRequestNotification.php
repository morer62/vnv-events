<?php

namespace App\Services;

final class PublicRequestNotification
{
    public static function send(
        EmailService $mailer,
        string $subject,
        string $body,
        string $reference,
        string $extraRecipient = ''
    ): bool {
        $recipients = [
            'contact@vnvevents.com',
            'info@vnvevents.com',
            'theresa.johnson@vnvevents.com',
        ];
        $extraRecipient = strtolower(trim($extraRecipient));
        if (filter_var($extraRecipient, FILTER_VALIDATE_EMAIL) && !in_array($extraRecipient, $recipients, true)) {
            $recipients[] = $extraRecipient;
        }

        $results = $mailer->sendBulkEmail($recipients, $subject, $body);
        foreach ($recipients as $recipient) {
            if (!($results[$recipient] ?? false)) {
                error_log('Public request ' . $reference . ' notification failed for ' . $recipient . '.');
            }
        }

        return in_array(true, $results, true);
    }
}
