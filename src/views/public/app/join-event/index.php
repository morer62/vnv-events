<?php

use App\Services\EventExecutionService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$join = static function (): void {
    $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? $_GET['code'] ?? $_SESSION['pending_event_code'] ?? ''));
    $recipient = max(0,(int)($_POST['recipient'] ?? $_GET['recipient'] ?? $_SESSION['pending_event_tip_recipient'] ?? 0));
    if (!preg_match('/^\d{5,6}$/', $code)) {
        MessageUtil::setMessage('Enter a valid event code.', 'Event access', 'error');
        LocationUtils::redirectInternal('join-event');
    }

    $service = new EventExecutionService();
    $space = $service->findByCode($code);
    if (!$space) {
        MessageUtil::setMessage('Event code not found.', 'Event access', 'error');
        LocationUtils::redirectInternal('join-event');
    }

    $user = LoginService::getSession();
    if (!$user) {
        $_SESSION['pending_event_code'] = $code;
        if($recipient>0)$_SESSION['pending_event_tip_recipient']=$recipient;
        $_SESSION['post_auth_redirect'] = 'join-event/continue';
        LocationUtils::redirectInternal('login');
    }

    try {
        $service->assertCanJoin($space, $user);
        $service->join($space, $user);
        unset($_SESSION['pending_event_code'], $_SESSION['pending_event_tip_recipient'], $_SESSION['post_auth_redirect']);
        LocationUtils::redirectInternal('panel/event-execution?code=' . rawurlencode($code) . ($recipient>0?'&recipient='.$recipient:''));
    } catch (Throwable $e) {
        MessageUtil::setMessage($e->getMessage(), 'Event access', 'error');
        LocationUtils::redirectInternal('join-event');
    }
};

$router->get(function () use ($join) {
    if (!empty($_GET['code'])) {
        $join();
    }
    if (LoginService::getSession() && !empty($_SESSION['pending_event_code'])) {
        LocationUtils::redirectInternal('join-event/continue');
    }
    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'message' => MessageUtil::getMessage(),
    ]);
});

$router->post($join);
$router->run();
