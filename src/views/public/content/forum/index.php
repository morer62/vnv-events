<?php

use App\Services\LoginService;
use App\Repositories\ForumCategoryRepository;
use App\Repositories\ForumTopicRepository;
use App\Repositories\ForumAttachmentRepository;
use App\Services\PublicSeoService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $categoryRepo = new ForumCategoryRepository();
    $topicRepo = new ForumTopicRepository();
    $attachmentRepo = new ForumAttachmentRepository();
    
    $categories = $categoryRepo->getAllWithStats();
    
    $categoryFilter = $_GET['category'] ?? null;
    $filter = $_GET['filter'] ?? 'recent'; // recent, comments, popular, views
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 15;
    $offset = ($page - 1) * $perPage;
    
    if ($categoryFilter) {
        $topics = $topicRepo->getTopicsByCategory((int)$categoryFilter, $perPage, $offset, $filter);
        $totalTopics = $topicRepo->countByCategory((int)$categoryFilter);
        $selectedCategory = $categoryRepo->getCategoryWithStats((int)$categoryFilter);
    } else {
        // Aplicar filtro según el parámetro con paginación
        switch ($filter) {
            case 'comments':
                $topics = $topicRepo->getRecentCommentsTopics($perPage, $offset);
                $totalTopics = $topicRepo->countRecentCommentsTopics();
                break;
            case 'popular':
                $topics = $topicRepo->getPopularTopics($perPage, $offset);
                $totalTopics = $topicRepo->countAllTopics();
                break;
            case 'views':
                $topics = $topicRepo->getMostViewedTopics($perPage, $offset);
                $totalTopics = $topicRepo->countAllTopics();
                break;
            case 'recent':
            default:
                $topics = $topicRepo->getRecentTopics($perPage, $offset);
                $totalTopics = $topicRepo->countAllTopics();
                break;
        }
        $selectedCategory = null;
    }
    
    // Obtener la primera imagen de cada topic
    foreach ($topics as &$topic) {
        $images = $attachmentRepo->getImagesByTopic($topic->id);
        $topic->first_image = !empty($images) ? $images[0] : null;
        $topic->images_count = count($images);
    }
    unset($topic);
    
    $totalPages = ceil($totalTopics / $perPage);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user,
        "categories" => $categories,
        "topics" => $topics,
        "selectedCategory" => $selectedCategory,
        "currentPage" => $page,
        "totalPages" => $totalPages,
        "currentFilter" => $filter,
        "pageTitle" => "Community Forum",
        "seo" => PublicSeoService::forumListSeo(),
        "schemaJson" => [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebPage',
                    '@id' => 'https://vnvevents.com/forums/#webpage',
                    'url' => 'https://vnvevents.com/forums/',
                    'name' => 'VNV Events Community Forums',
                    'description' => 'Public VNV Events community discussions for event planning questions and ideas.',
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => 'https://vnvevents.com/forums/#breadcrumb',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://vnvevents.com/'],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Forums', 'item' => 'https://vnvevents.com/forums/'],
                    ],
                ],
            ],
        ],
    ]);
});

$router->run();

