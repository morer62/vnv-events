<?php

use App\Repositories\MusicSessionRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $platform = $_GET['platform'] ?? 'youtube';
    $search = $_GET['search'] ?? null;
    $categoryId = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : null;
    
    if (!in_array(strtolower($platform), ['youtube', 'soundcloud', 'spotify'])) {
        $platform = 'youtube';
    }
    
    $sessionRepo = new MusicSessionRepository();
    $categories = $sessionRepo->getPublicCategoriesWithCounts($platform, $search);
    $sessions = $categoryId ? $sessionRepo->getPublicSessionsByPlatform($platform, $search, $categoryId) : [];
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'sessions' => $sessions,
        'categories' => $categories,
        'selected_category' => $categoryId,
        'selected_platform' => strtolower($platform),
        'search_query' => $search,
        'base_url' => $_ENV["APP_URL"] ?? '/',
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

