<?php

namespace App\Utils;

use App\Services\LoginService;

class FeatureFlags
{
    public static function contractsEnabled(): bool
    {
        $user = LoginService::getSession();
        $env = getenv('STRIPE_CONTRACTS_ENABLED');

        return $env === 'true' && $user && $user->getLevel() === 1;
    }
}
