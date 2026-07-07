<?php

namespace App\Utils;

use Exception;

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

        set_exception_handler(function ($e) {

            error_log("\nUncaught Exception: " . $e->getMessage(), 3, self::$logFile);
            error_log("\n".$e->getTraceAsString(), 3, self::$logFile);
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                error_log("\nFatal Error: {$error['message']} in {$error['file']} on line {$error['line']}", 3, self::$logFile);
                // You can also dump debug_backtrace() here
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

}
