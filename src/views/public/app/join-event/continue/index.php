<?php

use App\Services\EventExecutionService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$user = LoginService::getSession();
if (!$user) {
    $_SESSION['post_auth_redirect'] = 'join-event/continue';
    LocationUtils::redirectInternal('login');
}

$code = preg_replace('/\D+/', '', (string)($_SESSION['pending_event_code'] ?? ''));
try {
    $service = new EventExecutionService();
    $space = preg_match('/^\d{5,6}$/', $code) ? $service->findByCode($code) : null;
    if (!$space) throw new RuntimeException('The pending event code is no longer available.');
    $service->assertCanJoin($space, $user);
    $service->join($space, $user);
    unset($_SESSION['pending_event_code'], $_SESSION['post_auth_redirect']);
    LocationUtils::redirectInternal('panel/event-execution?code=' . rawurlencode($code));
} catch (Throwable $e) {
    unset($_SESSION['pending_event_code'], $_SESSION['post_auth_redirect']);
    MessageUtil::setMessage($e->getMessage());
    LocationUtils::redirectInternal('join-event');
}
