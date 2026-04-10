<?php

use App\Repositories\MusicSessionRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $platform = $_GET['platform'] ?? 'youtube';
    $search = $_GET['search'] ?? null;
    
    if (!in_array(strtolower($platform), ['youtube', 'soundcloud', 'spotify'])) {
        $platform = 'youtube';
    }
    
    $sessionRepo = new MusicSessionRepository();
    $sessions = $sessionRepo->getPublicSessionsByPlatform($platform, $search);
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'sessions' => $sessions,
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

