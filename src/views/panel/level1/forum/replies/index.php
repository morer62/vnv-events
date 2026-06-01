<?php

use App\Repositories\ForumReplyRepository;
use App\Repositories\ForumTopicRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;
use App\Utils\CSRF;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $topicId = (int)($_GET['id'] ?? 0);

    if (!$topicId) {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $replyRepo = new ForumReplyRepository();
    $topic = $topicRepo->getTopicWithAuthor($topicId);

    if (!$topic) {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        'topic' => $topic,
        'replies' => $replyRepo->getRepliesForModeration($topicId),
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    CSRF::validateCSRF();
    $replyId = (int)($_POST['reply_id'] ?? 0);
    $status = strtoupper($_POST['status'] ?? '');

    if (!$user || (int)$user->getLevel() !== 1 || !$replyId || !in_array($status, ['APPROVED', 'HIDDEN', 'REJECTED', 'DELETED'], true)) {
        MessageUtil::setMessage("Invalid moderation action.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    $replyRepo = new ForumReplyRepository();
    $topicId = $replyRepo->moderate($replyId, $status, (int)$user->getId());

    if ($topicId) {
        (new ForumTopicRepository())->refreshReplyStats($topicId);
        MessageUtil::setMessage("Reply moderated.");
        LocationUtils::redirectInternal("panel/forum/replies?id=" . $topicId);
        return;
    }

    MessageUtil::setMessage("Reply not found.");
    LocationUtils::redirectInternal("panel/forum");
});

$router->run();
