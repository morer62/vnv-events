<?php
namespace App\Services;

use App\Entity\User;
use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Utils\LocationUtils;


class NotificationService
{
    public static function sendExpoNotification(string $expoToken, string $title, string $body, array $data = []): void
    {
        $result = self::postExpoNotification($expoToken, $title, $body, $data);
        if (!($result['ok'] ?? false)) {
            error_log('[ExpoPush] Send failed: ' . json_encode($result));
        }
    }

    public static function sendExpoNotificationWithResult(string $expoToken, string $title, string $body, array $data = []): array
    {
        return self::postExpoNotification($expoToken, $title, $body, $data);
    }

    private static function postExpoNotification(string $expoToken, string $title, string $body, array $data = []): array
    {
        $message = [
            "to" => $expoToken,
            "sound" => "default",
            "title" => $title,
            "body" => $body
        ];

        if (!empty($data)) {
            $message['data'] = $data;
        }

        $payload = json_encode($message);

        $ch = curl_init("https://exp.host/--/api/v2/push/send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);

        $raw = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return [
                'ok' => false,
                'transport_error' => $curlError,
                'status_code' => $statusCode,
            ];
        }

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $expoStatus = is_array($data) ? (string)($data['status'] ?? '') : '';
        $details = is_array($data) ? ($data['details'] ?? []) : [];
        $expoError = is_array($details) ? (string)($details['error'] ?? '') : '';

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300 && $expoStatus === 'ok',
            'status_code' => $statusCode,
            'expo_status' => $expoStatus,
            'ticket_id' => is_array($data) ? ($data['id'] ?? null) : null,
            'message' => is_array($data) ? ($data['message'] ?? null) : null,
            'expo_error' => $expoError ?: null,
            'response' => is_array($decoded) ? $decoded : (is_string($raw) ? substr($raw, 0, 500) : null),
        ];
    }


    /**
     * Permite enviar una notificación a múltiples usuarios según su ID
     * Solo se envía si el usuario tiene un expo_token válido.
     */
    public static function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        $userRepo = new UserRepository();

        foreach ($userIds as $id) {
            $user = $userRepo->getOneWithoutOwnership(['id' => $id]);
            if ($user && $user->expo_token) {
                $result = self::postExpoNotification($user->expo_token, $title, $body, $data);
                if ($result['ok'] ?? false) {
                    continue;
                }

                $expoError = (string)($result['expo_error'] ?? '');
                error_log('[ExpoPush] User ' . (int)$id . ' send failed: ' . json_encode($result));

                if ($expoError === 'DeviceNotRegistered') {
                    $userRepo->clearExpoToken((int)$id);
                    error_log('[ExpoPush] Cleared stale Expo token for user ' . (int)$id);
                }
            }
        }
    }
}
