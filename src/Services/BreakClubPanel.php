<?php

namespace App\Services;

use App\Entity\User;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;

final class BreakClubPanel
{
    public const ADMIN = 'panel/event-productions/break-club';
    public const MEMBER = 'panel/break-club';

    public static function inClubTenant(User $user): bool
    {
        return SiteContext::siteKey() === 'vnvevents'
            && (int)$user->getOwner() === SiteContext::businessOwnerId();
    }

    public static function requireUser(int $level): User
    {
        $user = LoginService::getSession();
        if (!$user) {
            LocationUtils::redirectInternal('login');
        }
        if ($user->getLevel() !== $level || !self::inClubTenant($user)) {
            http_response_code(403);
            exit('This area is unavailable for this account.');
        }
        header('Cache-Control: private, no-store');
        if (!BreakClubService::hasVerifiedLogin($user)) {
            http_response_code(401);
            exit(BreakClubService::signInPrompt($user));
        }
        return $user;
    }

    public static function showNavigation(?User $user): bool
    {
        if (!$user || !self::inClubTenant($user)) {
            return false;
        }
        if ($user->getLevel() === 1) {
            return true;
        }
        if ($user->getLevel() !== 5 || !BreakClubService::hasVerifiedLogin($user)) {
            return false;
        }
        try {
            return (new BreakClubService())->memberForUser($user) !== null;
        } catch (\PDOException $e) {
            // A club database outage must not prevent a customer from using other areas.
            error_log('[Break Club navigation] ' . $e->getMessage());
            return false;
        }
    }

    public static function token(User $user): string
    {
        $key = (int)$user->getId() . ':' . $user->getLevel();
        $_SESSION['break_club_panel_csrf'][$key] ??= bin2hex(random_bytes(32));
        return $_SESSION['break_club_panel_csrf'][$key];
    }

    public static function requireToken(User $user, mixed $submitted): void
    {
        if (!is_string($submitted) || !hash_equals(self::token($user), $submitted)) {
            http_response_code(403);
            exit('Please reload the club page and try again.');
        }
    }

    public static function flash(string $text, string $type = 'success'): void
    {
        $_SESSION['break_club_notice'] = ['text' => $text, 'type' => $type];
    }

    public static function notice(): ?array
    {
        $notice = $_SESSION['break_club_notice'] ?? null;
        unset($_SESSION['break_club_notice']);
        return $notice;
    }
}
