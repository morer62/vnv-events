<?php

namespace App\Utils;

use Exception;
use Throwable;

class ErrorLogging
{

    private static string $logFile;
    private static string $logDir;

    public static function init(): void
    {

        self::$logDir = LocationUtils::getRootLocation() . '/.logs';
        self::$logFile = self::$logDir . '/app_error_'.date('Y-m-d').'.log';

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0777, true); // create recursively if missing
        }

        error_reporting(E_ALL);
        ini_set('display_errors', 0); // don't show in browser
        ini_set('log_errors', 1);

        set_exception_handler(function (Throwable $e) {
            self::writeException('Uncaught Exception', $e);
            self::renderFailureResponse('Application error', $e->getMessage(), $e->getTraceAsString());
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                $message = "{$error['message']} in {$error['file']} on line {$error['line']}";
                error_log("\nFatal Error: {$message}", 3, self::$logFile);
                self::renderFailureResponse('Fatal error', $message);
            }
        });

    }

    public static function log(Exception $exception): void
    {
        error_log("\nLogged Exception: " . $exception->getMessage(), 3, self::$logFile);
        error_log("\n".$exception->getTraceAsString(), 3, self::$logFile);
    }

    public static function warning(string $message): void
    {
        error_log("\nWarning: " . $message, 3, self::$logFile);
    }

    private static function writeException(string $label, Throwable $exception): void
    {
        error_log("\n{$label}: " . $exception->getMessage(), 3, self::$logFile);
        error_log("\n" . $exception->getTraceAsString(), 3, self::$logFile);
    }

    private static function renderFailureResponse(string $title, string $message, ?string $trace = null): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(500);

        if (self::isApiRequest()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $title,
                'debug' => self::shouldDisplayDetails() ? $message : null,
            ]);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeLog = htmlspecialchars(self::$logFile, ENT_QUOTES, 'UTF-8');
        $details = '';

        if (self::shouldDisplayDetails()) {
            $safeTrace = htmlspecialchars((string)$trace, ENT_QUOTES, 'UTF-8');
            $details = "<section><h2>Debug details</h2><pre>{$safeMessage}\n\n{$safeTrace}</pre></section>";
        }

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VNV Events | {$safeTitle}</title>
    <style>
        body{margin:0;min-height:100vh;background:#f5f7fb;color:#0f172a;font-family:Inter,Arial,sans-serif;display:grid;place-items:center;padding:24px}
        main{width:min(860px,100%);background:#fff;border:1px solid #dbe4ef;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.12);padding:28px}
        h1{margin:0 0 10px;font-size:26px}
        p{color:#475569;line-height:1.55}
        code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:2px 6px}
        pre{white-space:pre-wrap;word-break:break-word;background:#111827;color:#f8fafc;border-radius:12px;padding:16px;overflow:auto;max-height:420px}
        a{color:#0f766e;font-weight:700}
    </style>
</head>
<body>
    <main>
        <h1>{$safeTitle}</h1>
        <p>The page could not finish loading. The error was written to <code>{$safeLog}</code>.</p>
        <p>For a level 1 admin, add <code>debug=1</code> to the URL to show the technical details on screen.</p>
        {$details}
    </main>
</body>
</html>
HTML;
    }

    private static function shouldDisplayDetails(): bool
    {
        $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? ''));
        $environment = strtolower((string)($_ENV['ENVIRONTMENT'] ?? $_ENV['ENVIRONMENT'] ?? ''));
        if (in_array($appEnv, ['debug', 'local', 'development'], true) || in_array($environment, ['dev', 'local', 'development'], true)) {
            return true;
        }

        if (($_GET['debug'] ?? '') !== '1') {
            return false;
        }

        try {
            $user = \App\Services\LoginService::getSession();
            return $user && (int)$user->getLevel() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private static function isApiRequest(): bool
    {
        $url = trim((string)($_GET['url'] ?? ''), '/');
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_starts_with($url, 'api/')
            || str_contains($accept, 'application/json')
            || $requestedWith === 'xmlhttprequest';
    }

}
