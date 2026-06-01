<?php

use App\Repositories\ForumReplyRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();

    if (!$user || (int)$user->getLevel() !== 5) {
        LocationUtils::redirectInternal("panel/home");
        return;
    }

    $replyRepo = new ForumReplyRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        'replies' => $replyRepo->getRepliesByUser((int)$user->getId()),
    ]);
});

$router->run();
