<?php

namespace App\Utils;

use App\Entity\User;
use App\Services\LoginService;

class TwigUtils {

    public static function getTreeRoutes($templateLocation) : array
    {
        $separator = preg_quote(DIRECTORY_SEPARATOR, '/'); // escapes \ if needed
        $locations = preg_split("/[$separator\/]/", $templateLocation);

        $paths = array_slice($locations, 2, -1);

        $finalPaths = [];

        for ($i = 0; $i < count($paths); $i++) {

            $currentPath = $paths[$i];

            if ($currentPath == "home"){
                continue;
            }

            $pathName = ucwords(str_replace("-", " ", $currentPath));
            $pathNavigation = "";

            for ($j = 0; $j <= $i; $j++) {
                $pathNavigation .= $paths[$j] . "/";
            }

            $finalPaths[] = [$pathName, $pathNavigation];
        }

        return $finalPaths;
    }

    public static function getCurrentLocation($templateChild) : string
    {
        $separator = preg_quote(DIRECTORY_SEPARATOR, '/'); // escapes \ if needed
        $locations = preg_split("/[$separator\/]/", $templateChild);

        $location = implode("/", array_slice($locations, 2, -1));

        if (str_contains($templateChild, "panel")) {
            $location = "panel/" . $location;
        }

        return $location;
    }

    public static function truncate($text, $length) : string
    {
        return mb_substr($text, 0, $length) . '...';
    }

    public static function htmlToText($html) : string
    {
        $result = strip_tags($html);
        $result = preg_replace('/\s+/', ' ', $result);

        // Trim whitespace
        $result = trim($result);
        return htmlspecialchars($result, ENT_QUOTES, 'UTF-8');
    }

    public static function jsonDecode($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function hasPerm ($module, $action): bool
    {
        $user = LoginService::getSession();
        return User::hasPermission($user, $module, $action);
    }
}
