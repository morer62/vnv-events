<?php

namespace App\Utils;

class CSRF
{
    public static function generateCSRF() {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    public static function validateCSRF() {
        $savedToken = $_SESSION['csrf_token'] ?? null;
        $token = $_POST["csrf_token"] ?? null;

        if (empty($savedToken) || empty($token)) {
            MessageUtil::setMessage("CSRF token no provided");
            LocationUtils::reload();
        }

        if ($savedToken !== $token) {
            MessageUtil::setMessage("Invalid CSRF token");
            LocationUtils::reload();
        }

        $_SESSION['csrf_token'] = null;
    }
}