<?php

namespace App\Services;

/**
 * Runs non-critical work after the HTTP response has reached the client.
 *
 * Payment persistence and redirects must never wait for SMTP or another remote
 * service. Under PHP-FPM, fastcgi_finish_request() flushes the redirect before
 * queued callbacks run. The explicit runner keeps this behavior testable.
 */
final class PostResponseTaskService
{
    /** @var array<int,callable> */
    private static array $tasks = [];
    private static bool $shutdownRegistered = false;

    public static function enqueue(callable $task): void
    {
        self::$tasks[] = $task;

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'runPending']);
    }

    public static function pendingCount(): int
    {
        return count(self::$tasks);
    }

    public static function runPending(bool $finishResponse = true): void
    {
        if ($finishResponse) {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                // Best-effort fallback for non-FPM SAPIs. The payment handlers
                // have already set their Location header before shutdown.
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @flush();
            }
        }

        $tasks = self::$tasks;
        self::$tasks = [];

        foreach ($tasks as $task) {
            try {
                $task();
            } catch (\Throwable $exception) {
                error_log('[PostResponseTask] ' . $exception->getMessage());
            }
        }
    }
}
