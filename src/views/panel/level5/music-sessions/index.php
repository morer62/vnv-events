<?php

use App\Repositories\MusicSessionRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    if (!$user || !in_array((int)$user->getLevel(), [4, 5], true)) {
        LocationUtils::redirectInternal('login');
    }

    $search = trim((string)($_GET['search'] ?? ''));
    $categoryId = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : null;

    $repo = new MusicSessionRepository();
    $categories = $repo->getPublicCategoriesWithCounts(null, null);
    $sessions = $repo->getPublicSessionsByPlatform(null, null, null);
    foreach ($sessions as $session) {
        if (empty($session->embed_code) && !empty($session->url)) {
            $session->embed_code = $repo->generateEmbedCode((string)$session->url, (string)$session->platform);
        }
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'user' => $user,
        'search' => $search,
        'categories' => $categories,
        'selected_category' => $categoryId,
        'sessions' => $sessions,
        'featured_session' => $sessions[0] ?? null,
    ]);
});

$router->run();
