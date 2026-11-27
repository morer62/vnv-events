<?php

use App\Services\RetroGlamEditions;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (empty($_SESSION['partnership_csrf'])) {
        $_SESSION['partnership_csrf'] = bin2hex(random_bytes(24));
    }
    $slug = is_string($_GET['edition'] ?? null) ? $_GET['edition'] : '';
    $intent = $_GET['intent'] ?? '';
    $editionTitle = RetroGlamEditions::TITLES[$slug] ?? null;
    $editionRequest = $editionTitle !== null && in_array($intent, ['waitlist', 'host'], true);
    if (!$editionRequest && (isset($_GET['edition']) || isset($_GET['intent']))) {
        LocationUtils::redirectInternal('vnv-events-productions/retro-glam#retro-editions');
    }
    $host = $editionRequest && $intent === 'host';
    if ($host && empty($_SESSION['quick_request_csrf'])) {
        $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    }
    $submitted = isset($_GET['submitted']);
    if ($editionRequest) {
        $submitted = $submitted && ($_SESSION['retro_confirmation'] ?? null) === [$slug, $intent];
        if ($submitted) {
            unset($_SESSION['retro_confirmation']);
        }
    }
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'partnership_csrf' => $_SESSION['partnership_csrf'],
        'quick_request_csrf' => $host ? $_SESSION['quick_request_csrf'] : '',
        'retro_edition' => $editionRequest ? $slug : '',
        'retro_title' => $editionRequest ? $editionTitle : '',
        'private_request' => $host,
        'submitted' => $submitted,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
