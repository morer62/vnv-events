<?php

namespace App\Utils;

use App\Entity\User;
use App\Services\LoginService;
use App\Services\PublicSeoService;
use App\Services\TranslationService;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Exception;

class TemplateResponse
{

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public static function render(string $templateLocation, array $data = []): string {

        $folders = explode(DIRECTORY_SEPARATOR."views".DIRECTORY_SEPARATOR, $templateLocation, 2);

        $templateParent = $folders[0] . DIRECTORY_SEPARATOR."views";
        $templateChild = $folders[1];

        // Specify the directory where your templates are located
        $loader = new FilesystemLoader($templateParent);

        // Initialize Twig environment
        $twig = new Environment($loader);

        $twig->addFunction(new TwigFunction('asset_for', [LocationUtils::class, 'assetFor']));
        $twig->addFunction(new TwigFunction('path', [LocationUtils::class, 'assetFor']));
        $twig->addFunction(new TwigFunction('trans', [TranslationService::class, 'trans']));
        $twig->addFunction(new TwigFunction('get_csrf', [CSRF::class, 'generateCSRF']));
        $twig->addFunction(new TwigFunction('csrf_token', [CSRF::class, 'generateCSRF']));
        $twig->addFunction(new TwigFunction('contain_permission', [TwigUtils::class, 'hasPerm']));
        $twig->addFilter(new TwigFilter('truncate', [TwigUtils::class, 'truncate']));
        $twig->addFilter(new TwigFilter('html_to_text', [TwigUtils::class, 'htmlToText']));
        $twig->addFilter(new TwigFilter('json_decode', [TwigUtils::class, 'jsonDecode']));
        $twig->addFunction(new TwigFunction('getTreeRoutes', function () use ($templateChild) {
            return TwigUtils::getTreeRoutes($templateChild);
        }));

        // Incluir notificaciones globalmente para todas las páginas
        $notifications = [];
        $notifications_count = 0;
        $chatUnreadThreads = [];
        $chatUnreadCount = 0;
        $chatUnreadBaseRoute = "panel/planner-hub/team/chat";
        
        try {
            // Solo cargar notificaciones si hay una sesión activa
            $session = LoginService::getSession();
            if ($session && $session->getId()) {
                // Solo cargar notificaciones si no se han cargado antes
                if (!isset($GLOBALS['notifications_loaded'])) {
                    $GLOBALS['notifications_loaded'] = true;
                    
                    $notificationsRepo = new \App\Repositories\NotificationsRepository();
                    $userId = $session->getId();

                    // Obtener TODAS las notificaciones ordenadas por tiempo (más recientes primero)
                    $allNotifications = $notificationsRepo->getByUser($userId);
                    $notifications_count = $notificationsRepo->getUnreadCount($userId);

                    // Filtrar solo las no leídas y tomar las primeras 5
                    $unreadNotifications = array_filter($allNotifications, function($notification) {
                        return $notification->leido == 0;
                    });

                    $notifications = array_slice($unreadNotifications, 0, 5);

                    // Establecer las variables globales para Twig
                    $GLOBALS['notifications'] = $notifications;
                    $GLOBALS['notifications_count'] = $notifications_count;
                } else {
                    // Usar las variables globales ya cargadas
                    $notifications = $GLOBALS['notifications'] ?? [];
                    $notifications_count = $GLOBALS['notifications_count'] ?? 0;
                }
            }
        } catch (Exception $e) {
            error_log("ERROR loading notifications in TemplateResponse: " . $e->getMessage());
            $notifications = [];
            $notifications_count = 0;
        }

        try {
            $session = LoginService::getSession();
            if ($session && $session->getId()) {
                $chatUnreadThreads = (new \App\Repositories\ChatThreadRepository())->getUnreadSummariesForUser((int)$session->getId());
                $chatUnreadCount = count($chatUnreadThreads);
                $chatUnreadBaseRoute = ((int)$session->getLevel() === 5) ? "panel/chat" : "panel/planner-hub/team/chat";
            }
        } catch (Exception $e) {
            error_log("ERROR loading chat unread summaries in TemplateResponse: " . $e->getMessage());
            $chatUnreadThreads = [];
            $chatUnreadCount = 0;
        }

        $currentCanonicalUrl = self::getCurrentCanonicalUrl();
        if (empty($data['schemaJson'])) {
            $data['schemaJson'] = PublicSeoService::defaultSchema(
                $currentCanonicalUrl,
                is_array($data['seo'] ?? null) ? $data['seo'] : [],
                $templateChild
            );
        }

        return $twig->render($templateChild, [
            "user" => LoginService::getSession(),
            "alertMessage" => MessageUtil::getMessage(),
            "env" => $_ENV,
            "current_canonical_url" => $currentCanonicalUrl,
            "current_location" => TwigUtils::getCurrentLocation($templateChild),
            "notifications" => $notifications,
            "notifications_count" => $notifications_count,
            "chat_unread_threads" => $chatUnreadThreads,
            "chat_unread_count" => $chatUnreadCount,
            "chat_unread_base_route" => $chatUnreadBaseRoute,
            "isMobileApp" => PlatformDetector::isMobileApp(),
            "isWeb" => PlatformDetector::isWeb(),
            ...$data
        ]);
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public static function renderAndDisplay(string $templateLocation, array $data = []): void {
        echo self::render($templateLocation, $data);
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public static function renderInTemplates(string $templateName, array $data = []): string {
        $templatesFolder  = __DIR__. DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "views";

        $loader = new FilesystemLoader($templatesFolder);

        // Initialize Twig environment
        $twig = new Environment($loader);

        $twig->addFunction(new TwigFunction('asset_for', [LocationUtils::class, 'assetFor']));
        $twig->addFunction(new TwigFunction('path', [LocationUtils::class, 'assetFor']));
        $twig->addFunction(new TwigFunction('trans', [TranslationService::class, 'trans']));
        $twig->addFunction(new TwigFunction('get_csrf', [CSRF::class, 'generateCSRF']));
        $twig->addFunction(new TwigFunction('csrf_token', [CSRF::class, 'generateCSRF']));
        $twig->addFilter(new TwigFilter('json_decode', [TwigUtils::class, 'jsonDecode']));

        // Incluir notificaciones globalmente para todas las páginas
        $notifications = [];
        $notifications_count = 0;
        $chatUnreadThreads = [];
        $chatUnreadCount = 0;
        $chatUnreadBaseRoute = "panel/planner-hub/team/chat";
        
        try {
            // Solo cargar notificaciones si hay una sesión activa
            $session = LoginService::getSession();
            if ($session && $session->getId()) {
                // Solo cargar notificaciones si no se han cargado antes
                if (!isset($GLOBALS['notifications_loaded'])) {
                    $GLOBALS['notifications_loaded'] = true;
                    
                    $notificationsRepo = new \App\Repositories\NotificationsRepository();
                    $userId = $session->getId();

                    // Obtener TODAS las notificaciones ordenadas por tiempo (más recientes primero)
                    $allNotifications = $notificationsRepo->getByUser($userId);
                    $notifications_count = $notificationsRepo->getUnreadCount($userId);

                    // Filtrar solo las no leídas y tomar las primeras 5
                    $unreadNotifications = array_filter($allNotifications, function($notification) {
                        return $notification->leido == 0;
                    });

                    $notifications = array_slice($unreadNotifications, 0, 5);

                    // Establecer las variables globales para Twig
                    $GLOBALS['notifications'] = $notifications;
                    $GLOBALS['notifications_count'] = $notifications_count;
                } else {
                    // Usar las variables globales ya cargadas
                    $notifications = $GLOBALS['notifications'] ?? [];
                    $notifications_count = $GLOBALS['notifications_count'] ?? 0;
                }
            }
        } catch (Exception $e) {
            error_log("ERROR loading notifications in TemplateResponse renderInTemplates: " . $e->getMessage());
            $notifications = [];
            $notifications_count = 0;
        }

        try {
            $session = LoginService::getSession();
            if ($session && $session->getId()) {
                $chatUnreadThreads = (new \App\Repositories\ChatThreadRepository())->getUnreadSummariesForUser((int)$session->getId());
                $chatUnreadCount = count($chatUnreadThreads);
                $chatUnreadBaseRoute = ((int)$session->getLevel() === 5) ? "panel/chat" : "panel/planner-hub/team/chat";
            }
        } catch (Exception $e) {
            error_log("ERROR loading chat unread summaries in TemplateResponse renderInTemplates: " . $e->getMessage());
            $chatUnreadThreads = [];
            $chatUnreadCount = 0;
        }

        $currentCanonicalUrl = self::getCurrentCanonicalUrl();
        if (empty($data['schemaJson'])) {
            $data['schemaJson'] = PublicSeoService::defaultSchema(
                $currentCanonicalUrl,
                is_array($data['seo'] ?? null) ? $data['seo'] : [],
                "templates" . DIRECTORY_SEPARATOR . $templateName
            );
        }

        return $twig->render("templates".DIRECTORY_SEPARATOR.$templateName, [
            "user" => LoginService::getSession(),
            "alertMessage" => MessageUtil::getMessage(),
            "env" => $_ENV,
            "current_canonical_url" => $currentCanonicalUrl,
            "notifications" => $notifications,
            "notifications_count" => $notifications_count,
            "chat_unread_threads" => $chatUnreadThreads,
            "chat_unread_count" => $chatUnreadCount,
            "chat_unread_base_route" => $chatUnreadBaseRoute,
            "isMobileApp" => PlatformDetector::isMobileApp(),
            "isWeb" => PlatformDetector::isWeb(),
            ...$data
        ]);
    }

    /**
     * Render a trusted Twig template stored outside the filesystem, such as a CMS
     * page body that contains full `{% extends %}` / `{% block %}` markup.
     *
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public static function renderString(string $templateSource, array $data = []): string
    {
        $templatesFolder = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "views";

        $loader = new ChainLoader([
            new ArrayLoader([
                "__cms_inline_template.twig" => $templateSource,
            ]),
            new FilesystemLoader($templatesFolder),
        ]);

        $twig = new Environment($loader);

        $twig->addFunction(new TwigFunction('asset_for', [LocationUtils::class, 'assetFor']));
        $twig->addFunction(new TwigFunction('path', [LocationUtils::class, 'assetFor']));
        $twig->addFunction(new TwigFunction('trans', [TranslationService::class, 'trans']));
        $twig->addFunction(new TwigFunction('get_csrf', [CSRF::class, 'generateCSRF']));
        $twig->addFunction(new TwigFunction('csrf_token', [CSRF::class, 'generateCSRF']));
        $twig->addFunction(new TwigFunction('contain_permission', [TwigUtils::class, 'hasPerm']));
        $twig->addFilter(new TwigFilter('truncate', [TwigUtils::class, 'truncate']));
        $twig->addFilter(new TwigFilter('html_to_text', [TwigUtils::class, 'htmlToText']));
        $twig->addFilter(new TwigFilter('json_decode', [TwigUtils::class, 'jsonDecode']));
        $twig->addFunction(new TwigFunction('getTreeRoutes', function () {
            return TwigUtils::getTreeRoutes("__cms_inline_template.twig");
        }));

        $currentCanonicalUrl = self::getCurrentCanonicalUrl();
        if (empty($data['schemaJson'])) {
            $data['schemaJson'] = PublicSeoService::defaultSchema(
                $currentCanonicalUrl,
                is_array($data['seo'] ?? null) ? $data['seo'] : [],
                "__cms_inline_template.twig"
            );
        }

        return $twig->render("__cms_inline_template.twig", [
            "user" => LoginService::getSession(),
            "alertMessage" => MessageUtil::getMessage(),
            "env" => $_ENV,
            "current_canonical_url" => $currentCanonicalUrl,
            "current_location" => TwigUtils::getCurrentLocation("__cms_inline_template.twig"),
            "notifications" => $GLOBALS['notifications'] ?? [],
            "notifications_count" => $GLOBALS['notifications_count'] ?? 0,
            "chat_unread_threads" => [],
            "chat_unread_count" => 0,
            "chat_unread_base_route" => "panel/planner-hub/team/chat",
            "isMobileApp" => PlatformDetector::isMobileApp(),
            "isWeb" => PlatformDetector::isWeb(),
            "GOOGLE_MAPS_API_KEY" => $_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: '',
            ...$data
        ]);
    }

    private static function getCurrentCanonicalUrl(): string
    {
        $baseUrl = 'https://vnvevents.com';
        $path = '/';

        if (class_exists(\Symfony\Component\HttpFoundation\Request::class)) {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            $path = $request->getPathInfo() ?: '/';
        } else {
            $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        }

        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/') . '/';
        }

        return $baseUrl . $path;
    }

}
