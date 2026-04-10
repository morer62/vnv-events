<?php

namespace App\Services;

class HashService
{
    public static function hashPassword($password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verifyPassword($password, $hash): bool {
        return password_verify($password, $hash);
    }

    public static function needsRehash($hash): bool {
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}