<?php

namespace App\Utils;

class LocationUtils
{

    public static function assetFor(string $uri, array $queryParams = []): string {
        $queryString = '';

        if (!empty($queryParams)) {
            $queryString = "?" . http_build_query($queryParams);
        }

        $uri = ltrim($uri, '/');
        return rtrim(self::getBasePath(), '/') . ($uri === '' ? '/' : '/' . $uri) . $queryString;
    }

    public static function pathFor(string $uri): string {
        $uri = ltrim($uri, '/');
        return rtrim(self::getBasePath(), '/') . ($uri === '' ? '/' : '/' . $uri);
    }

    public static function getBasePath(): string {
        // Prefer explicit APP_URL from .env when available
        if (!empty($_ENV['APP_URL'])) {
            return rtrim($_ENV['APP_URL'], '/');
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $serverPort = $_SERVER['SERVER_PORT'] ?? '80';
        $serverScheme = $_SERVER['REQUEST_SCHEME'] ?? "http";

        if (isset($_SERVER["HTTP_X_FORWARDED_PROTO"])) {
            $serverScheme = $_SERVER["HTTP_X_FORWARDED_PROTO"];
        }

        if (isset($_SERVER["HTTP_X_FORWARDED_HOST"])) {
            $serverName = $_SERVER["HTTP_X_FORWARDED_HOST"];
        }

        $fileName = "$serverScheme://$serverName";

        if ($serverPort != 80 && $serverPort != 443) {
            $fileName .= ":$serverPort";
        }

        // Always ensure the project root folder is part of the base path
        $rootFolder = "/" . self::getRootFolderName();
        if (!str_contains($fileName, $rootFolder)) {
            $fileName .= $rootFolder;
        }

        return $fileName;
    }

    public static function getRootFolderName() : string
    {
        $folderLocation = dirname(__DIR__, 2);
        $folderLocationArray = explode(DIRECTORY_SEPARATOR, $folderLocation);
        return end($folderLocationArray);
    }

    public static function getRootLocation(): string {
        $folderLocation = dirname(__DIR__, 2);
        return $folderLocation;
    }

    public static function redirectTo(string $path): never
    {
        header("Location: $path");
        exit();
    }

    public static function redirectInternal(string $path): never
    {
        $internalPath = self::pathFor($path);
        header("Location: $internalPath");
        exit();
    }

    public static function reload(): never {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];
        $fullUrl = $protocol . "://" . $host . $uri;

        header("Location: $fullUrl");
        exit();
    }

    /**
     * Obtiene la ruta absoluta a un template, similar a path() en Twig
     * @param string $templatePath Ruta relativa del template (ej: "emails/new_order.php")
     * @return string Ruta absoluta del template
     */
    public static function getTemplatePath(string $templatePath): string {
        return self::getRootLocation() . "/src/views/templates/" . $templatePath;
    }
}
