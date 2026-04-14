<?php

use App\Services\LoginService;
use App\Repositories\ForumTopicRepository;
use App\Repositories\ForumReplyRepository;
use App\Repositories\ForumLikeRepository;
use App\Repositories\ForumAttachmentRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $topicId = $_GET['id'] ?? null;

    if (!$topicId) {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("forum");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $replyRepo = new ForumReplyRepository();
    $likeRepo = new ForumLikeRepository();
    $attachmentRepo = new ForumAttachmentRepository();

    $topic = $topicRepo->getTopicWithAuthor((int)$topicId);

    if (!$topic || !$topic->is_approved) {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("forum");
        return;
    }

    $topicRepo->incrementViewCount((int)$topicId);

    $replies = $replyRepo->getRepliesWithNested((int)$topicId);
    $attachments = $attachmentRepo->getAttachmentsByTopic((int)$topicId);
    
    // DEBUG
    error_log("Topic ID: " . $topicId);
    error_log("Attachments count: " . count($attachments));
    error_log("Attachments data: " . json_encode($attachments));

    $userLikedTopic = false;
    $userLikedReplies = [];

    if ($user) {
        $userLikedTopic = $likeRepo->hasUserLikedTopic($user->getId(), (int)$topicId);
        foreach ($replies as $reply) {
            if ($likeRepo->hasUserLikedReply($user->getId(), (int)$reply->id)) {
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
        "userLikedReplies" => $userLikedReplies
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    
    if (!$user) {
        MessageUtil::setMessage("You must be logged in to reply.");
        LocationUtils::redirectInternal("login");
        return;
    }

    $topicId = $_POST['topic_id'] ?? null;
    $content = trim($_POST['content'] ?? '');
    $parentReplyId = !empty($_POST['parent_reply_id']) ? (int)$_POST['parent_reply_id'] : null;

    if (!$topicId || empty($content)) {
        MessageUtil::setMessage("Please provide a reply.");
        LocationUtils::redirectInternal("forum/topic?id=" . $topicId);
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $topic = $topicRepo->getOne(['id' => $topicId]);

    if (!$topic || $topic->is_locked) {
        MessageUtil::setMessage("This topic is locked.");
        LocationUtils::redirectInternal("forum/topic?id=" . $topicId);
        return;
    }

    $replyRepo = new ForumReplyRepository();
    $replyRepo->add([
        'id_topic' => $topicId,
        'id_user' => $user->getId(),
        'id_parent_reply' => $parentReplyId,
        'content' => $content,
        'is_approved' => 1
    ]);

    MessageUtil::setMessage("✅ Reply added successfully!");
    LocationUtils::redirectInternal("forum/topic?id=" . $topicId);
});

$router->run();

