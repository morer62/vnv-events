<?php

use App\Services\LoginService;
use App\Services\SeoFilesGeneratorService;
use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$render = function (array $lastResult = []) {
    $context = UserContext::get();
    $generator = new SeoFilesGeneratorService();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        'files' => $generator->getFileCards(),
        'audit' => $generator->buildAudit(),
        'submitUrls' => [
            'Google Search Console Sitemap' => 'https://vnvevents.com/sitemap.xml',
            'Robots.txt' => 'https://vnvevents.com/robots.txt',
            'LLMs.txt' => 'https://vnvevents.com/llms.txt',
            'Full AI Context' => 'https://vnvevents.com/llms-full.txt',
        ],
        'lastResult' => $lastResult,
    ]);
};

$router->get(function () use ($render) {
    return $render();
});

$router->post(function () {
    $user = LoginService::getSession();
    $level = $user ? (int)$user->getLevel() : 0;

    if (!in_array($level, [1, 6], true)) {
        MessageUtil::setMessage("SEO Center is reserved for authorized administrators.", "Access denied", "danger");
        LocationUtils::redirectInternal("panel");
        return;
    }

    CSRF::validateCSRF();

    $action = $_POST['action'] ?? 'all';
    $allowed = ['all', 'sitemap', 'robots', 'llms', 'llms_full'];
    if (!in_array($action, $allowed, true)) {
        MessageUtil::setMessage("Invalid SEO generation action.", "Error", "danger");
        LocationUtils::reload();
        return;
    }

    $generator = new SeoFilesGeneratorService();
    $result = $generator->generate($action, method_exists($user, 'getId') ? (int)$user->getId() : null);

    $status = $result['status'] ?? 'failed';
    $type = $status === 'success' ? 'success' : ($status === 'partial' ? 'warning' : 'danger');
    MessageUtil::setMessage($result['message'] ?? 'SEO files processed.', 'SEO Center', $type);

    LocationUtils::reload();
});

$router->run();
