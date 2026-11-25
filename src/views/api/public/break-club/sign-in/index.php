<?php

use App\Services\BreakClubService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
if (SiteContext::siteKey() !== 'vnvevents') { http_response_code(404); return; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); return; }
try {
    BreakClubService::requireCsrf($_POST['_token'] ?? null);
} catch (DomainException $error) {
    http_response_code(403); echo 'Please refresh and sign in again.'; return;
}
$target = ($_POST['target'] ?? '') === 'admin' ? 'panel/event-productions/break-club' : 'panel/break-club';
$token = $_POST['token'] ?? '';
if (($_POST['target'] ?? '') === 'claim' && is_string($token) && preg_match('/^[0-9a-f]{64}$/D', $token)) {
    $target = 'api/public/break-club/access?token=' . $token;
}
LoginService::logout();
$_SESSION['post_auth_redirect'] = $target;
LocationUtils::redirectInternal('login');
