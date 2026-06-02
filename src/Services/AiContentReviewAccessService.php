<?php

namespace App\Services;

class AiContentReviewAccessService
{
    private const SESSION_KEY = 'ai_content_review_unlocked';
    private const COOKIE_KEY = 'ai_content_review_unlocked';

    public function isUnlocked(): bool
    {
        if (!empty($_SESSION[self::SESSION_KEY])) {
            return true;
        }

        $token = (string)($_COOKIE[self::COOKIE_KEY] ?? '');
        if ($token === '') {
            return false;
        }

        return hash_equals($this->expectedCookieToken(), $token);
    }

    public function unlock(string $password): bool
    {
        $expected = $this->reviewPassword();
        if ($expected === '' || !hash_equals($expected, $password)) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = true;
        $days = max(1, (int)($_ENV['AI_CONTENT_REVIEW_REMEMBER_DAYS'] ?? 30));
        setcookie(self::COOKIE_KEY, $this->expectedCookieToken(), [
            'expires' => time() + ($days * 86400),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);

        return true;
    }

    public function lock(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        setcookie(self::COOKIE_KEY, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    private function reviewPassword(): string
    {
        return trim((string)($_ENV['AI_CONTENT_REVIEW_PASSWORD'] ?? ''));
    }

    private function expectedCookieToken(): string
    {
        $secret = (string)($_ENV['AI_CONTENT_REVIEW_PASSWORD'] ?? '');
        $appUrl = (string)($_ENV['APP_URL'] ?? 'vnv-events');

        return hash('sha256', $secret . '|ai-content-review|' . $appUrl);
    }
}
