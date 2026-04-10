<?php

namespace App\Utils;

class ParamsUtils
{
    private static function getUrl() {
        if (isset($_GET["url"])) {
            $url = rtrim($_GET["url"], '/');
            $url = filter_var($url, FILTER_SANITIZE_URL);
            return explode('/', strtolower($url));
        }

        return [];
    }

    public static function getRouteParams(): array
    {
        $urls = self::getUrl();
        $params = [];

        foreach ($urls as $value) {
            if (is_numeric($value) && ctype_digit($value)) {
                $params[] = (int) $value;
            }
        }

        return $params;
    }

    public static function getQueryParams(): array
    {
        return $_GET;
    }
}