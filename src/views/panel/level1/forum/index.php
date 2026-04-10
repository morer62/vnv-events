<?php

use App\Services\LoginService;
use App\Repositories\ForumTopicRepository;
use App\Repositories\ForumCategoryRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();

    $topicRepo = new ForumTopicRepository();
    $categoryRepo = new ForumCategoryRepository();

    $categoryFilter = $_GET['category'] ?? null;
    $search = $_GET['search'] ?? '';

    if (!empty($search)) {
        $topics = $topicRepo->searchTopics($search, 100);
    } elseif ($categoryFilter) {
        $topics = $topicRepo->getTopicsByCategory((int)$categoryFilter, 100, 0);
    } else {
        $topics = $topicRepo->getRecentTopics(100);
    }

    $categories = $categoryRepo->getActiveCategories();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "topics" => $topics,
        "categories" => $categories,
        "categoryFilter" => $categoryFilter,
        "search" => $search
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $action = $_POST['action'] ?? '';
    $topicId = $_POST['topic_id'] ?? null;

    if (!$topicId) {
        MessageUtil::setMessage("⚠️ Topic ID is required.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    $topicRepo = new ForumTopicRepository();

    switch ($action) {
        case 'toggle_pin':
            $topic = $topicRepo->getOne(['id' => $topicId]);
            if ($topic) {
                $topicRepo->update(
                    ['is_pinned' => $topic->is_pinned ? 0 : 1],
                    ['id' => $topicId]
                );
                MessageUtil::setMessage("✅ Topic " . ($topic->is_pinned ? "unpinned" : "pinned") . " successfully!");
            }
            break;

        case 'toggle_lock':
            $topic = $topicRepo->getOne(['id' => $topicId]);
            if ($topic) {
                $topicRepo->update(
                    ['is_locked' => $topic->is_locked ? 0 : 1],
                    ['id' => $topicId]
                );
                MessageUtil::setMessage("✅ Topic " . ($topic->is_locked ? "unlocked" : "locked") . " successfully!");
            }
            break;

        case 'delete':
            $topicRepo->delete(['id' => $topicId]);
            MessageUtil::setMessage("✅ Topic deleted successfully!");
            break;
    }

    LocationUtils::redirectInternal("panel/forum");
});

$router->run();





