<?php
namespace App\Services;

use App\Entity\User;
use App\Repositories\UserRepository;
use App\Repositories\UserRolesRepository;
use App\Utils\LocationUtils;


class NotificationService
{
    public static function sendExpoNotification(string $expoToken, string $title, string $body): void
    {
        $payload = json_encode([
            "to" => $expoToken,
            "sound" => "default",
            "title" => $title,
            "body" => $body
        ]);

        $ch = curl_init("https://exp.host/--/api/v2/push/send");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }


    /**
     * Permite enviar una notificación a múltiples usuarios según su ID
     * Solo se envía si el usuario tiene un expo_token válido.
     */
    public static function sendToUsers(array $userIds, string $title, string $body): void
    {
        $userRepo = new UserRepository();

        foreach ($userIds as $id) {
            $user = $userRepo->getOneWithoutOwnership(['id' => $id]);
            if ($user && $user->expo_token) {
                self::sendExpoNotification($user->expo_token, $title, $body);
            }
        }
    }
}
