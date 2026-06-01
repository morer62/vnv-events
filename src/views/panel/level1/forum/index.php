<?php

use App\Repositories\ForumCategoryRepository;
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

    $topicRepo = new ForumTopicRepository();
    $categoryRepo = new ForumCategoryRepository();

    $categoryFilter = $_GET['category'] ?? null;
    $search = trim($_GET['search'] ?? '');

    $topics = $topicRepo->getAdminTopics($categoryFilter ? (int)$categoryFilter : null, $search, 100);
    $categories = $categoryRepo->getActiveCategories();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "topics" => $topics,
        "categories" => $categories,
        "categoryFilter" => $categoryFilter,
        "search" => $search,
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $action = $_POST['action'] ?? '';
    $topicId = $_POST['topic_id'] ?? null;

    if (!$user || (int)$user->getLevel() !== 1) {
        MessageUtil::setMessage("This action is reserved for administrators.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    CSRF::validateCSRF();

    if (!$topicId) {
        MessageUtil::setMessage("Topic ID is required.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $topic = $topicRepo->getOne(['id' => $topicId]);

    if (!$topic) {
        MessageUtil::setMessage("Topic not found.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    switch ($action) {
        case 'toggle_pin':
            $topicRepo->update(['is_pinned' => $topic->is_pinned ? 0 : 1], ['id' => $topicId]);
            MessageUtil::setMessage($topic->is_pinned ? "Topic unpinned." : "Topic pinned.");
            break;

        case 'toggle_lock':
            $topicRepo->update([
                'is_locked' => $topic->is_locked ? 0 : 1,
                'allow_replies' => $topic->is_locked ? 1 : 0,
            ], ['id' => $topicId]);
            MessageUtil::setMessage($topic->is_locked ? "Topic unlocked." : "Topic locked.");
            break;

        case 'toggle_publish':
            $isPublished = ($topic->status ?? 'PUBLISHED') === 'PUBLISHED' && (int)$topic->is_approved === 1;
            $topicRepo->update([
                'status' => $isPublished ? 'PAUSED' : 'PUBLISHED',
                'is_approved' => $isPublished ? 0 : 1,
                'published_at' => $isPublished ? $topic->published_at : date('Y-m-d H:i:s'),
            ], ['id' => $topicId]);
            MessageUtil::setMessage($isPublished ? "Topic unpublished." : "Topic published.");
            break;

        case 'delete':
            $topicRepo->update([
                'status' => 'DELETED',
                'is_approved' => 0,
                'deleted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $topicId]);
            MessageUtil::setMessage("Topic hidden from public forum.");
            break;
    }

    LocationUtils::redirectInternal("panel/forum");
});

$router->run();
