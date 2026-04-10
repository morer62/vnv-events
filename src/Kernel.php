<?php

namespace App;

use App\Core\FindViewTrait;
use App\Core\GetViewTrait;
use App\Core\IncludeViewTrait;
use App\Entity\User;
use App\Services\ConfigService;
use App\Services\LoginService;
use App\Services\ValidationSessionService;
use App\Utils\ErrorLogging;
use App\Utils\LocationUtils;
use App\Repositories\InstitutionProfileRepository;
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
    private static string $errorIndex = "505.php";
    private static string $notFoundApi = "404.php";
    private static array $urlViews = [];

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
            // Cargar servicios necesarios
            $affiliateService = new \App\Services\AffiliateService();
            
            // Obtener parámetros UTM
            $utmSource = $_GET['utm_source'] ?? null;
            $utmMedium = $_GET['utm_medium'] ?? null;
            $utmCampaign = $_GET['utm_campaign'] ?? null;
            
            // Procesar el click de afiliado
            $success = $affiliateService->processAffiliateClick($affiliateCode, $utmSource, $utmMedium, $utmCampaign);
            
            if (!$success) {
                LocationUtils::redirectInternal("/");
                return;
            }
            
            // Redirigir según el estado de sesión
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

    public function __construct() {

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
            return explode('/', strtolower($url));
        }

        return [];
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
                $this->includeViewAndExit($this->getPublicView([self::$homeIndex]));
            }

            // Vistas privadas (panel)
            if ($urlViews[0] == self::$protectedFolderViews) {
                $user = LoginService::getSession();
                if ($user instanceof User) {
                    LoginService::verifyMany([
                        fn () => LoginService::verifyPhoneConfirmation($urlViews),
                    ]);

                    $path = implode("/", $urlViews);

                    // Verificación de membresía
                    if (str_contains($path, "planner-hub") && in_array($user->getLevel(), [2, 3])) {
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

                    // 🔐 Verificación de permisos para rutas tipo /panel/planner-hub/management/{module}
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
            if ($urlViews[0] == self::$apiUrlPrefix) {
                $this->includeViewAndExit($this->getApiViews($urlViews));
            }

            // Manejar rutas de afiliado específicamente
            if (count($urlViews) >= 2 && $urlViews[0] === 'r') {
                $this->handleAffiliateRoute($urlViews[1]);
            }

            // Vista pública
            $this->includeViewAndExit($this->getPublicView($urlViews));
        } catch (Exception $exception) {
            if ($_ENV["APP_ENV"] == "debug") {
                throw $exception;
            }

            $this->includeViewAndExit($this->getPublicView([self::$errorIndex]));
        }
    }
}
