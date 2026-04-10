<?php

namespace App\Utils;

class PlatformDetector
{
    public static function isMobileApp(): bool
    {
        return isset($_SESSION['IS_MOBILE_APP']) && $_SESSION['IS_MOBILE_APP'] === true;
    }

    public static function isWeb(): bool
    {
        return !isset($_SESSION['IS_MOBILE_APP']) || $_SESSION['IS_MOBILE_APP'] === false;
    }

    public static function setMobileApp(bool $isMobile = true): void
    {
        $_SESSION['IS_MOBILE_APP'] = $isMobile;
    }

    public static function reset(): void
    {
        $_SESSION['IS_MOBILE_APP'] = false;
    }
}

