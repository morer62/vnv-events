<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$publicIndex = (string)file_get_contents($root . '/public/index.php');
$userRepository = (string)file_get_contents($root . '/src/Repositories/UserRepository.php');
$login = (string)file_get_contents($root . '/src/views/api/auth/login.php');
$loginService = (string)file_get_contents($root . '/src/Services/LoginService.php');
$apiAuth = (string)file_get_contents($root . '/src/Services/ApiAuthService.php');
$dashboard = (string)file_get_contents($root . '/src/views/api/mobile/dashboard/index.php');
$navigation = (string)file_get_contents($root . '/src/views/api/mobile/navigation/index.php');

foreach (['session.gc_maxlifetime', 'session.cookie_lifetime', "'samesite' => 'Lax'", '60 * 60 * 24 * 180'] as $needle) {
    $assert(str_contains($publicIndex, $needle), "Persistent PHP session contract missing: {$needle}");
}

foreach (['getOrCreateApiToken', "api_token IS NULL OR TRIM(api_token) = ''", 'random_bytes(32)'] as $needle) {
    $assert(str_contains($userRepository, $needle), "Durable API token contract missing: {$needle}");
}
$assert(str_contains($login, 'getOrCreateApiToken'), 'Login still does not reuse the durable mobile token.');
$assert(str_contains($loginService, 'getOneWithoutOwnership(["api_token" => $token])'), 'Token validation still inherits stale owner scope.');
$assert(str_contains($loginService, 'is_active'), 'Inactive accounts are not rejected during token validation.');
$assert(str_contains($loginService, 'self::synchronizeManagementModules($user);'), 'Token validation does not load Level 4 management grants.');

foreach (['management_modules', 'UserPermissions::managementModules()', 'hasPermissionForModule'] as $needle) {
    $assert(str_contains($apiAuth, $needle), "Mobile user permissions payload missing: {$needle}");
}
$assert(str_contains($apiAuth, 'HTTP_X_VNV_MOBILE_TOKEN'), 'Custom mobile auth header is not supported.');
foreach (['pending_proposals', 'mobileDashboardManagementModules', 'management_modules'] as $needle) {
    $assert(str_contains($dashboard, $needle), "Mobile dashboard contract missing: {$needle}");
}
foreach (['navigation_version\' => 4', 'Client CRM', 'Sponsors CRM', 'Proposals & Menus', 'VNV Studio', 'Podcasts'] as $needle) {
    $assert(str_contains($navigation, $needle), "Mobile navigation contract missing: {$needle}");
}

echo "PASS durable mobile session and Level 1/4/5 dashboard contracts\n";
