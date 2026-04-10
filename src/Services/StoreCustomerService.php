<?php

namespace App\Services;

use App\Repositories\UserRepository;

class StoreCustomerService
{
    public static function findOrCreateLevel5User(
        int $ownerId,
        string $fullName,
        string $email,
        ?string $phone = null
    ): ?object {
        $userRepo = new UserRepository();

        $existingUser = $userRepo->getOneWithoutOwnership([
            'email' => $email
        ]);

        if ($existingUser) {
            return $existingUser;
        }

        $fullName = trim($fullName);
        $parts = preg_split('/\s+/', $fullName, 2);

        $firstName = trim($parts[0] ?? 'Customer');
        $lastName = trim($parts[1] ?? '');

        $temporaryPasswordPlain = self::generateTemporaryPassword();
        $temporaryPasswordHash = password_hash($temporaryPasswordPlain, PASSWORD_DEFAULT);

        $ok = $userRepo->add([
            'name' => $firstName !== '' ? $firstName : 'Customer',
            'lastname' => $lastName,
            'email' => trim($email),
            'password' => $temporaryPasswordHash,
            'password_updated' => 1,
            'level' => '5',
            'phone' => $phone ?: null,
            'phone_code' => '',
            'phone_validation' => 1,
            'membership_type' => 'FREE',
            'id_owner' => $ownerId,
            'allow_chat_with_clients' => 0,
            'is_active' => 1,
            'ui_language' => 'en',
            'system_language' => 'en'
        ]);

        if (!$ok) {
            return null;
        }

        $newUserId = $userRepo->getLastId();

        $newUser = $userRepo->getOneWithoutOwnership([
            'id' => $newUserId
        ]);

        if ($newUser) {
            $newUser->temporary_password_plain = $temporaryPasswordPlain;
        }

        return $newUser ?: null;
    }

    public static function generateTemporaryPassword(int $length = 12): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$!';
        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }

    public static function wasJustCreated(object $user): bool
    {
        return isset($user->temporary_password_plain) && !empty($user->temporary_password_plain);
    }
}