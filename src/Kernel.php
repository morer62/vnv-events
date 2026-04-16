<?php

namespace App;

use App\Core\FindViewTrait;
use App\Core\GetViewTrait;
use App\Core\IncludeViewTrait;
use App\Entity\User;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Repositories\LocationPagesRepository;
use App\Services\ConfigService;
use App\Services\LoginService;
use App\Services\ValidationSessionService;
use App\Utils\ErrorLogging;
use App\Utils\LocationUtils;
use Closure;
use Exception;

ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class Kernel
{
    private static string $protectedFolderViews = "panel";
    private static string $publicFolderViews = "public";
    private static string $apiFolderViews = "api";

    private static string $protectedUrlPrefix = "panel";
    private static string $apiUrlPrefix = "api";

    private static string $homeIndex = "planner-hub";
    private static string $notFoundIndex = "404.php";
    private static string $errorIndex = "500.php";
    private static string $notFoundApi = "404.php";
    private static array $urlViews = [];

    /**
     * Public folders (internal routing)
     *
     * Estas carpetas existen solo internamente.
     * La URL NO las muestra.
     *
     * Ej:
     * /login → public/auth/login/index.php
     *
     * Para agregar una nueva carpeta:
     * 1. Crear en /public/{carpeta}
     * 2. Agregar aquí en el array
     *
     * ⚠️ No repetir slugs entre carpetas
     */
    private static array $publicFolders = [
        'app',
        'auth',
        'commerce',
        'content',
        'system',
        'pages',
    ];

    /**
     * @var array|Closure[] $VERIFYING_SESSION_MIDDLEWARES
     * These middlewares are executed in order to verify if the user
     * has the necessary permissions to access the requested view.
     */
    private static array $VERIFYING_SESSION_MIDDLEWARES = [];

    use GetViewTrait, IncludeViewTrait, FindViewTrait;

    public static function getHomeIndex(): string
    {
        return self::$homeIndex;
    }

    private function handleAffiliateRoute($affiliateCode): void
    {
        try {
            $affiliateService = new \App\Services\AffiliateService();

            $utmSource = $_GET['utm_source'] ?? null;
            $utmMedium = $_GET['utm_medium'] ?? null;
            $utmCampaign = $_GET['utm_campaign'] ?? null;

            $success = $affiliateService->processAffiliateClick($affiliateCode, $utmSource, $utmMedium, $utmCampaign);

            if (!$success) {
                LocationUtils::redirectInternal("/");
                return;
            }

            $redirectTo = $_GET['redirect'] ?? '/';

            if (!isset($_SESSION['user'])) {
                $redirectTo = '/signup?from_affiliate=1';
            }

            LocationUtils::redirectInternal($redirectTo);
        } catch (\Exception $e) {
            LocationUtils::redirectInternal("/");
        }
    }

    private function getNotFoundView(): string
    {
        return LocationUtils::getRootLocation() . "/src/views/public/" . self::$notFoundIndex;
    }

    public function __construct()
    {
        $timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
        date_default_timezone_set($timezone);

        ConfigService::init();
        ErrorLogging::init();

        self::$urlViews = $this->getUrlViews();

        self::$VERIFYING_SESSION_MIDDLEWARES = [
            fn () => ValidationSessionService::verifyPhoneConfirmation(self::$urlViews),
            fn () => ValidationSessionService::verifyInstitutionProfile(self::$urlViews),
            fn () => ValidationSessionService::verifyMembership(self::$urlViews),
            fn () => ValidationSessionService::verifyUserPermissions(self::$urlViews),
        ];
    }

    private function getUrlViews(): array
    {
        if (isset($_GET["url"])) {
            $url = rtrim($_GET["url"], '/');
            $url = filter_var($url, FILTER_SANITIZE_URL);

            if ($url === '') {
                return [];
            }

            return explode('/', strtolower($url));
        }

        return [];
    }

    /**
     * Resuelve rutas públicas ignorando la carpeta intermedia.
     *
     * Ejemplos:
     * /login       -> /src/views/public/auth/login/index.php
     * /store       -> /src/views/public/commerce/store/index.php
     * /planner-hub -> /src/views/public/app/planner-hub/index.php
     */
    private function resolvePublicView(array $urlViews): ?string
    {
        if (empty($urlViews)) {
            return null;
        }

        $root = LocationUtils::getRootLocation();
        $publicRoot = $root . "/src/views/public";
        $slug = implode('/', $urlViews);

        // 1) Buscar directamente en /public
        $directIndex = $publicRoot . "/{$slug}/index.php";
        if (file_exists($directIndex)) {
            return $directIndex;
        }

        $directPhp = $publicRoot . "/{$slug}.php";
        if (file_exists($directPhp)) {
            return $directPhp;
        }

        // 2) Buscar dentro de carpetas transparentes
        foreach (self::$publicFolders as $folder) {
            $indexPath = $publicRoot . "/{$folder}/{$slug}/index.php";
            if (file_exists($indexPath)) {
                return $indexPath;
            }

            $phpPath = $publicRoot . "/{$folder}/{$slug}.php";
            if (file_exists($phpPath)) {
                return $phpPath;
            }
        }

        // 3) Casos especiales
        if ($slug === 'logout') {
            $logoutPath = $publicRoot . "/auth/logout.php";
            if (file_exists($logoutPath)) {
                return $logoutPath;
            }
        }

        return null;
    }

    private function includeResolvedFileAndExit(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception("Resolved file not found: " . $filePath);
        }

        include $filePath;
        exit;
    }

    private function isReservedDynamicSlug(string $slug): bool
    {
        $reserved = [
            'login',
            'logout',
            'signup',
            'register',
            'contact',
            'panel',
            'api',
            'planner-hub',
            'store',
            'terms',
            'privacy',
            'about',
            'services',
            'locations',
            'venues',
            'vendors',
            'pages',
            'content',
            'auth',
            'commerce',
            'system',
            'app',
            'r',
            'category',
        ];

        return in_array($slug, $reserved, true);
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        try {
            $urlViews = $this->getUrlViews();

            // Acceso vía token (app móvil)
            if (count($urlViews) > 1 && $urlViews[0] === self::$protectedUrlPrefix && $urlViews[1] === "tokenapi") {
                $token = $urlViews[2] ?? null;
                $internalRoute = array_slice($urlViews, 3);

                if ($token && count($internalRoute)) {
                    $user = LoginService::validateToken($token);

                    if ($user instanceof User) {
                        LoginService::setSession($user);
                        $_SESSION['IS_MOBILE_APP'] = true;
                        $redirectTo = implode("/", $internalRoute);
                        LocationUtils::redirectInternal($redirectTo);
                    }
                }

                LocationUtils::redirectInternal("login");
            }

            // Home por defecto
            if (empty($urlViews)) {
                $resolvedHome = $this->resolvePublicView([self::$homeIndex]);

                if ($resolvedHome) {
                    $this->includeResolvedFileAndExit($resolvedHome);
                }

                $this->includeViewAndExit($this->getPublicView([self::$homeIndex]));
            }

            // Vistas privadas (panel)
            if ($urlViews[0] === self::$protectedFolderViews) {
                $user = LoginService::getSession();

                if ($user instanceof User) {
                    LoginService::verifyMany([
                        fn () => LoginService::verifyPhoneConfirmation($urlViews),
                    ]);

                    $path = implode("/", $urlViews);

                    // Verificación de membresía
                    if (str_contains($path, "planner-hub") && in_array($user->getLevel(), [2, 3], true)) {
                        if (!$user->hasActiveMembership()) {
                            LocationUtils::redirectInternal("panel/membership/pay");
                        }

                        // Verificación de perfil institucional
                        if (!str_contains($path, "institution-profile")) {
                            $institutionRepo = new InstitutionProfileRepository();
                            $institutionProfile = $institutionRepo->getByOwner($user->getOwner());

                            if (!$institutionProfile) {
                                \App\Utils\MessageUtil::setMessage("🏢 Please complete your institution profile before continuing.");
                                LocationUtils::redirectInternal("panel/planner-hub/institution-profile");
                            }
                        }
                    }

                    // Verificación de permisos para rutas tipo /panel/planner-hub/management/{module}
                    if (
                        $user->getLevel() === 4 &&
                        isset($urlViews[1], $urlViews[2], $urlViews[3]) &&
                        $urlViews[1] === 'planner-hub' &&
                        $urlViews[2] === 'management'
                    ) {
                        $module = $urlViews[3];

                        if (!$user->hasPermissionForModule($module)) {
                            \App\Utils\MessageUtil::setMessage("ℹ️ This area is reserved for administrators. Let us know if you need help finding what you are looking for.");
                            LocationUtils::redirectInternal("panel/planner-hub/management");
                        }
                    }

                    if (count($urlViews) === 1) {
                        LocationUtils::redirectInternal("panel/home");
                    }

                    $this->includeAdminViewAndExit($this->getPrivateView($urlViews, $user));
                } else {
                    LocationUtils::redirectInternal("login");
                }
            }

            // API
            if ($urlViews[0] === self::$apiUrlPrefix) {
                $this->includeViewAndExit($this->getApiViews($urlViews));
            }

            // Rutas de afiliado
            if (count($urlViews) >= 2 && $urlViews[0] === 'r') {
                $this->handleAffiliateRoute($urlViews[1]);
            }

            // Location pages dinámicas por slug raíz
            // Ej: /sunrise -> src/views/public/pages/location-page/index.php
            if (count($urlViews) === 1) {
                $slug = $urlViews[0];

                if (!$this->isReservedDynamicSlug($slug)) {
                    $repo = new LocationPagesRepository();
                    $page = $repo->getPublishedBySlug($slug);

                    if ($page) {
                        $locationPageView = LocationUtils::getRootLocation()
                            . "/src/views/public/pages/location-page/index.php";

                        $this->includeResolvedFileAndExit($locationPageView);
                    }
                }
            }

            // Vista pública resuelta por archivos físicos
            $resolvedPublicView = $this->resolvePublicView($urlViews);

            if ($resolvedPublicView) {
                $this->includeResolvedFileAndExit($resolvedPublicView);
            }

            // Blog category pública: /category/blog/{slug}/
            if (
                count($urlViews) === 3 &&
                $urlViews[0] === 'category' &&
                $urlViews[1] === 'blog' &&
                !empty($urlViews[2])
            ) {
                $blogCategoryView = LocationUtils::getRootLocation()
                    . "/src/views/public/pages/blog-category/index.php";

                $this->includeResolvedFileAndExit($blogCategoryView);
            }

            // Store product category pública: /product-category/{slug}/
            if (
                count($urlViews) === 2 &&
                $urlViews[0] === 'product-category' &&
                !empty($urlViews[1])
            ) {
                $productCategoryView = LocationUtils::getRootLocation()
                    . "/src/views/public/pages/product-category/index.php";

                $this->includeResolvedFileAndExit($productCategoryView);
            }

            // Store product public page: /product/{slug}/
            if (
                count($urlViews) === 2 &&
                $urlViews[0] === 'product' &&
                !empty($urlViews[1])
            ) {
                $productView = LocationUtils::getRootLocation()
                    . "/src/views/public/pages/product/index.php";

                $this->includeResolvedFileAndExit($productView);
            }

            // CMS dinámico por cms_routes
            $cmsRoutePath = '/' . implode('/', $urlViews);
            if ($cmsRoutePath !== '/') {
                $cmsRoutePath .= '/';
            }

            $cmsRoutesRepository = new CmsRoutesRepository();
            $cmsRoutesRepository->db = new \App\Repositories\Connection();

            $cmsRoute = $cmsRoutesRepository->getByRoute($cmsRoutePath, 'en');

            if ($cmsRoute && ($cmsRoute->content_status ?? '') === 'PUBLISHED') {
                if (($cmsRoute->type ?? '') === 'page') {
                    $cmsContentView = LocationUtils::getRootLocation()
                        . "/src/views/public/pages/cms-content/index.php";

                    $this->includeResolvedFileAndExit($cmsContentView);
                }

                if (($cmsRoute->type ?? '') === 'post') {
                    $blogPostView = LocationUtils::getRootLocation()
                        . "/src/views/public/pages/blog-post/index.php";

                    $this->includeResolvedFileAndExit($blogPostView);
                }
            }

            // Fallback por compatibilidad
            $this->includeViewAndExit($this->getPublicView($urlViews));
        } catch (Exception $exception) {
            if (($_ENV["APP_ENV"] ?? 'production') === "debug") {
                throw $exception;
            }

            $errorView = LocationUtils::getRootLocation() . "/src/views/public/" . self::$errorIndex;

            if (file_exists($errorView)) {
                $this->includeResolvedFileAndExit($errorView);
            }

            $this->includeResolvedFileAndExit($this->getNotFoundView());
        }
    }
}