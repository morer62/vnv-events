<?php

namespace App\Utils;

use App\Services\LoginService;
use App\Entity\User;

class UserContext {
    public static function get(): array {
        $user = LoginService::getSession();

        if (!$user) {
            return [
                "user" => null,
                "level" => null,
                "user_id" => null,
                "permissions" => [],
                "can" => fn($module, $action) => false,
                "hasModule" => fn($module) => false,
                "isMobileApp" => PlatformDetector::isMobileApp(),
                "isWeb" => PlatformDetector::isWeb()
            ];
        }

        $level = $user->getLevel();
        $permissions = $user->getPermissions2();
        $userId = $user->getId();

        return [
            "user" => $user,
            "level" => $level,
            "user_id" => $userId,
            "permissions" => $permissions,
            "can" => function ($module, $action) use ($user, $level) {
                if ($level !== 4) return true;
                return User::hasPermission($user, $module, $action);
            },
            "hasModule" => function ($module) use ($permissions, $level) {
                if ($level !== 4) return true;

                foreach ($permissions as $perm) {
                    if (strtolower($perm->getModule()) === strtolower($module)) {
                        return true;
                    }
                }

                return false;
            },
            "isMobileApp" => PlatformDetector::isMobileApp(),
            "isWeb" => PlatformDetector::isWeb()
        ];
    }
}
