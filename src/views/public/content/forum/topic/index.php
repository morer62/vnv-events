<?php

use App\Repositories\ForumAttachmentRepository;
use App\Repositories\ForumLikeRepository;
use App\Repositories\ForumReplyRepository;
use App\Repositories\ForumTopicRepository;
use App\Services\LoginService;
use App\Services\PublicSeoService;
use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $topicId = $_GET['id'] ?? null;
    $topicSlug = $GLOBALS['forum_topic_slug'] ?? ($_GET['slug'] ?? null);

    if (!$topicId && !$topicSlug) {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("forums");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $replyRepo = new ForumReplyRepository();
    $likeRepo = new ForumLikeRepository();
    $attachmentRepo = new ForumAttachmentRepository();

    $topic = $topicSlug
        ? $topicRepo->getPublishedBySlug((string)$topicSlug)
        : $topicRepo->getTopicWithAuthor((int)$topicId);

    if (!$topic || !(int)$topic->is_approved || ($topic->status ?? 'PUBLISHED') !== 'PUBLISHED') {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("forums");
        return;
    }

    $topicId = (int)$topic->id;
    $topicRepo->incrementViewCount($topicId);

    $replies = $replyRepo->getRepliesWithNested($topicId);
    $attachments = $attachmentRepo->getAttachmentsByTopic($topicId);

    $userLikedTopic = false;
    $userLikedReplies = [];

    if ($user) {
        $userLikedTopic = $likeRepo->hasUserLikedTopic((int)$user->getId(), $topicId);
        foreach ($replies as $reply) {
            if ($likeRepo->hasUserLikedReply((int)$user->getId(), (int)$reply->id)) {
                $userLikedReplies[] = (int)$reply->id;
            }
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user,
        "topic" => $topic,
        "replies" => $replies,
        "attachments" => $attachments,
        "userLikedTopic" => $userLikedTopic,
        "userLikedReplies" => $userLikedReplies,
        "canReply" => $user && in_array((int)$user->getLevel(), [1, 5], true),
        "replyRequiresApproval" => filter_var($_ENV['FORUM_REPLIES_REQUIRE_APPROVAL'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        "seo" => PublicSeoService::forumTopicSeo($topic),
        "schemaJson" => PublicSeoService::forumTopicSchema($topic),
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();

    if (!$user) {
        MessageUtil::setMessage("You must be logged in to reply.");
        LocationUtils::redirectInternal("forums");
        return;
    }

    CSRF::validateCSRF();

    if (!in_array((int)$user->getLevel(), [1, 5], true)) {
        MessageUtil::setMessage("Only client community accounts can reply to public forum topics.");
        LocationUtils::redirectInternal("forums");
        return;
    }

    $topicId = $_POST['topic_id'] ?? null;
    $content = trim($_POST['content'] ?? '');
    $parentReplyId = !empty($_POST['parent_reply_id']) ? (int)$_POST['parent_reply_id'] : null;

    if (!$topicId || $content === '') {
        MessageUtil::setMessage("Please provide a reply.");
        LocationUtils::redirectInternal("forums");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $topic = $topicRepo->getOne(['id' => $topicId]);

    if (!$topic || (int)$topic->is_locked || (int)($topic->allow_replies ?? 1) !== 1) {
        MessageUtil::setMessage("This topic is locked.");
        LocationUtils::redirectInternal("forums/" . ($topic->slug ?? ''));
        return;
    }

    $requireApproval = filter_var($_ENV['FORUM_REPLIES_REQUIRE_APPROVAL'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $approved = (int)$user->getLevel() === 1 || !$requireApproval;

    $replyRepo = new ForumReplyRepository();
    $replyRepo->createReply(
        (int)$topicId,
        (int)$user->getId(),
        $content,
        $parentReplyId,
        (int)($topic->id_owner ?? $user->getOwner()),
        $approved
    );
    $topicRepo->refreshReplyStats((int)$topicId);

    MessageUtil::setMessage($approved ? "Reply added successfully!" : "Reply submitted and waiting for approval.");
    LocationUtils::redirectInternal("forums/" . ($topic->slug ?? ''));
});

$router->run();
