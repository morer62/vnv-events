<?php

namespace App\Services;

class TranslationService
{
    private static ?string $currentLocale = null;
    private static array $translations = [];
    private static array $loadedFiles = [];
    
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'pt', 'fr'];
    private const LEGACY_LOCALE_MAP = [
        'pr' => 'pt',
    ];
    private const TRANSLATIONS_PATH = __DIR__ . '/../Languages/';

    /**
     * Detecta y establece el idioma actual
     */
    public static function detectLocale(): string
    {
        self::$currentLocale = self::DEFAULT_LOCALE;
        $_SESSION['locale'] = self::DEFAULT_LOCALE;
        return self::DEFAULT_LOCALE;

        // 0. Prioridad: Si hay usuario autenticado, usar su idioma guardado
        if (class_exists('\App\Services\LoginService')) {
            $user = \App\Services\LoginService::getSession();
            if ($user && method_exists($user, 'getUiLanguage')) {
                $userLocale = self::normalizeLocale($user->getUiLanguage());
                if ($userLocale && in_array($userLocale, self::SUPPORTED_LOCALES)) {
                    self::$currentLocale = $userLocale;
                    $_SESSION['locale'] = self::$currentLocale;
                    return self::$currentLocale;
                }
            }
        }

        // 1. Verificar si hay un idioma guardado en sesión
        if (isset($_SESSION['locale']) && self::isSupportedLocale($_SESSION['locale'])) {
            self::$currentLocale = self::normalizeLocale($_SESSION['locale']);
            $_SESSION['locale'] = self::$currentLocale;
            return self::$currentLocale;
        }

        // 2. Verificar cookie (sincronizada con localStorage del frontend)
        if (isset($_COOKIE['app_locale']) && self::isSupportedLocale($_COOKIE['app_locale'])) {
            self::$currentLocale = self::normalizeLocale($_COOKIE['app_locale']);
            $_SESSION['locale'] = self::$currentLocale;
            return self::$currentLocale;
        }

        // 3. Detectar idioma del navegador
        $browserLocale = self::detectBrowserLocale();
        if ($browserLocale && in_array($browserLocale, self::SUPPORTED_LOCALES)) {
            self::$currentLocale = $browserLocale;
            $_SESSION['locale'] = self::$currentLocale;
            return self::$currentLocale;
        }

        // 4. Fallback a inglés
        self::$currentLocale = self::DEFAULT_LOCALE;
        $_SESSION['locale'] = self::$currentLocale;
        return self::$currentLocale;
    }

    /**
     * Detecta el idioma del navegador desde Accept-Language header
     */
    public static function detectBrowserLocale(): ?string
    {
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return null;
        }

        $acceptLanguage = trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        if ($acceptLanguage === '') {
            return null;
        }

        $languages = [];
        $position = 0;
        foreach (explode(',', $acceptLanguage) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = array_map('trim', explode(';', $part));
            $locale = self::normalizeLocale($segments[0] ?? null);
            if (!$locale || !in_array($locale, self::SUPPORTED_LOCALES, true)) {
                $position++;
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($segments, 1) as $segment) {
                if (stripos($segment, 'q=') === 0) {
                    $quality = max(0.0, min(1.0, (float) substr($segment, 2)));
                    break;
                }
            }

            if ($quality <= 0) {
                $position++;
                continue;
            }

            if (!isset($languages[$locale]) || $quality > $languages[$locale]['quality']) {
                $languages[$locale] = [
                    'quality' => $quality,
                    'position' => $position,
                ];
            }

            $position++;
        }

        if (empty($languages)) {
            return null;
        }

        uasort($languages, static function (array $a, array $b): int {
            if ($a['quality'] === $b['quality']) {
                return $a['position'] <=> $b['position'];
            }

            return $b['quality'] <=> $a['quality'];
        });

        return array_key_first($languages);
    }

    /**
     * Establece el idioma manualmente
     */
    public static function setLocale(string $locale): bool
    {
        $locale = self::normalizeLocale($locale) ?? '';
        if (!in_array($locale, self::SUPPORTED_LOCALES)) {
            return false;
        }

        self::$currentLocale = $locale;
        $_SESSION['locale'] = $locale;
        
        // También establecer cookie para sincronización con localStorage
        setcookie('app_locale', $locale, [
            'expires' => time() + (365 * 24 * 60 * 60), // 1 año
            'path' => '/',
            'httponly' => false, // Permitir acceso desde JavaScript
            'samesite' => 'Lax'
        ]);

        // Limpiar traducciones cargadas para forzar recarga
        self::$translations = [];
        self::$loadedFiles = [];

        return true;
    }

    /**
     * Obtiene el idioma actual
     */
    public static function getCurrentLocale(): string
    {
        if (self::$currentLocale === null) {
            self::detectLocale();
        }
        return self::$currentLocale ?? self::DEFAULT_LOCALE;
    }

    /**
     * Traduce una clave
     */
    public static function trans(string $key, array $params = []): string
    {
        $locale = self::getCurrentLocale();
        $translation = self::getTranslation($key, $locale);

        // Si no se encuentra, intentar con inglés como fallback
        if ($translation === $key && $locale !== self::DEFAULT_LOCALE) {
            $translation = self::getTranslation($key, self::DEFAULT_LOCALE);
        }

        // Reemplazar parámetros (:key y {key})
        if (!empty($params)) {
            foreach ($params as $paramKey => $paramValue) {
                $value = (string) $paramValue;
                $translation = str_replace(':' . $paramKey, $value, $translation);
                $translation = str_replace('{' . $paramKey . '}', $value, $translation);
            }
        }

        return $translation;
    }

    /**
     * Obtiene una traducción específica
     */
    private static function getTranslation(string $key, string $locale): string
    {
        // Cargar el archivo de traducción si no está cargado
        if (!isset(self::$loadedFiles[$locale])) {
            self::loadTranslations($locale);
        }

        // Buscar la clave (soporta notación de punto para arrays anidados)
        $keys = explode('.', $key);
        $value = self::$translations[$locale] ?? [];

        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $key; // Retornar la clave si no se encuentra
            }
        }

        return is_string($value) ? $value : $key;
    }

    /**
     * Carga las traducciones desde el archivo JSON
     */
    private static function loadTranslations(string $locale): void
    {
        $locale = self::normalizeLocale($locale) ?? $locale;
        $filePath = self::TRANSLATIONS_PATH . $locale . '.json';

        if (!file_exists($filePath)) {
            self::$translations[$locale] = [];
            self::$loadedFiles[$locale] = true;
            return;
        }

        $content = file_get_contents($filePath);
        $translations = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Error loading translations for locale {$locale}: " . json_last_error_msg());
            self::$translations[$locale] = [];
        } else {
            self::$translations[$locale] = $translations ?? [];
        }

        self::$loadedFiles[$locale] = true;
    }

    /**
     * Obtiene todos los idiomas soportados
     */
    public static function getSupportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * Obtiene el nombre del idioma en su idioma nativo
     */
    public static function getLocaleName(string $locale): string
    {
        $locale = self::normalizeLocale($locale) ?? $locale;
        $names = [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'pt' => 'Português',
        ];

        return $names[$locale] ?? $locale;
    }

    /**
     * Obtiene el idioma del sistema del usuario autenticado (para contratos/documentos)
     */
    public static function getSystemLanguage(): string
    {
        if (class_exists('\App\Services\LoginService')) {
            $user = \App\Services\LoginService::getSession();
            if ($user && method_exists($user, 'getSystemLanguage')) {
                $systemLang = self::normalizeLocale($user->getSystemLanguage());
                if ($systemLang && in_array($systemLang, self::SUPPORTED_LOCALES)) {
                    return $systemLang;
                }
            }
        }

        // Fallback al idioma de la UI o inglés
        return self::getCurrentLocale();
    }

    public static function normalizeLocale(?string $locale): ?string
    {
        if (!$locale) {
            return null;
        }

        $locale = strtolower(trim($locale));
        $locale = str_replace('_', '-', $locale);
        $language = substr($locale, 0, 2);

        return self::LEGACY_LOCALE_MAP[$locale]
            ?? self::LEGACY_LOCALE_MAP[$language]
            ?? $language;
    }

    public static function isSupportedLocale(?string $locale): bool
    {
        $normalized = self::normalizeLocale($locale);
        return $normalized !== null && in_array($normalized, self::SUPPORTED_LOCALES, true);
    }

    public static function normalizeSupportedLocale(?string $locale, string $fallback = self::DEFAULT_LOCALE): string
    {
        $normalized = self::normalizeLocale($locale);
        if ($normalized !== null && in_array($normalized, self::SUPPORTED_LOCALES, true)) {
            return $normalized;
        }

        return self::isSupportedLocale($fallback) ? (self::normalizeLocale($fallback) ?? self::DEFAULT_LOCALE) : self::DEFAULT_LOCALE;
    }
}
