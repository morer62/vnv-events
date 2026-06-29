<?php

use App\Services\LoginService;
use App\Services\SeoFilesGeneratorService;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $service = new SeoFilesGeneratorService();
    $baseUrl = SiteContext::publicBaseUrl();

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'files' => $service->getFileCards(),
        'audit' => $service->buildAudit(),
        'sitemapUrl' => $baseUrl . '/sitemap.xml',
        'submitUrls' => [
            'Sitemap' => $baseUrl . '/sitemap.xml',
            'Robots' => $baseUrl . '/robots.txt',
            'LLMs' => $baseUrl . '/llms.txt',
            'LLMs Full' => $baseUrl . '/llms-full.txt',
        ],
    ]);
});

$router->post(function () {
    $action = strtolower(trim($_POST['action'] ?? 'all'));
    $allowed = ['all', 'sitemap', 'robots', 'llms', 'llms_full', 'save_file'];

    if (!in_array($action, $allowed, true)) {
        MessageUtil::setMessage('Invalid SEO action.', 'Error', 'error');
        \App\Utils\LocationUtils::reload();
        return;
    }

    $user = LoginService::getSession();
    $service = new SeoFilesGeneratorService();
    if ($action === 'save_file') {
        $fileType = strtolower(trim($_POST['file_type'] ?? ''));
        $content = (string)($_POST['content'] ?? '');
        $result = $service->saveEditableFile($fileType, $content, $user?->getId());
    } else {
        $result = $service->generate($action, $user?->getId());
    }
    $status = $result['status'] ?? 'failed';

    MessageUtil::setMessage(
        $result['message'] ?? 'SEO files regenerated.',
        $status === 'failed' ? 'Error' : 'Success',
        $status === 'failed' ? 'error' : 'success'
    );

    \App\Utils\LocationUtils::reload();
});

$router->run();
