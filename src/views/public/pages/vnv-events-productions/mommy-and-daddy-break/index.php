<?php

use App\Services\BreakClubService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$router->get(function () {
    header('Cache-Control: private, no-store');
    $error = $_SESSION['break_club_error'] ?? '';
    $received = !empty($_SESSION['break_club_received']);
    unset($_SESSION['break_club_error'], $_SESSION['break_club_received']);
    $attribution = [];
    foreach (['utm_source', 'utm_campaign', 'utm_content'] as $key) {
        $raw = $_GET[$key] ?? '';
        $attribution[$key] = is_string($raw) ? substr(preg_replace('/[^a-z0-9_.-]/i', '', $raw), 0, 100) : '';
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'club_csrf' => BreakClubService::csrfToken(),
        'club_categories' => BreakClubService::CATEGORIES,
        'club_error' => $error,
        'club_received' => $received,
        'club_checkout_cancelled' => ($_GET['club'] ?? '') === 'cancelled',
        'club_attribution' => $attribution,
    ]);
});
$router->run();
