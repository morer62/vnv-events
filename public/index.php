<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Kernel;

// The mobile app restores panel access through a durable API token, but its
// WebViews still use the PHP session after the token hand-off. Keep that
// session durable as well so an iOS/Android WebView process restart does not
// unexpectedly send an active app user back to login.
$sessionLifetime = 60 * 60 * 24 * 180;
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.cookie_lifetime', (string)$sessionLifetime);
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$app = new Kernel();
$app->run();
