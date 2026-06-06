<?php

use App\Services\LoginService;
use App\Repositories\MusicSessionRepository;
use App\Repositories\MusicSessionsCategoryRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    $sessionRepo = new MusicSessionRepository();
    $categoryRepo = new MusicSessionsCategoryRepository();

    $userId = $user->getId();
    $sessions = $sessionRepo->getAllWithCategory($userId);
    $categories = $categoryRepo->getAllByUser($userId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "sessions" => $sessions,
        "categories" => $categories
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $sessionRepo = new MusicSessionRepository();
    $id = $_POST["id"] ?? null;

    if (!$id) {
        MessageUtil::setMessage("Invalid session ID.");
        LocationUtils::reload();
    }

    $session = $sessionRepo->getOne(["id" => $id]);

    if (!$session) {
        MessageUtil::setMessage("Session not found.");
        LocationUtils::reload();
    }

    $sessionRepo->delete(["id" => $id]);

    MessageUtil::setMessage("multimedia session deleted successfully.");
    LocationUtils::reload();
});

$router->run();

