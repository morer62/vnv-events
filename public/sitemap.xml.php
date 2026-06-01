<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\ForumTopicRepository;
use App\Repositories\LocationPagesRepository;

header('Content-Type: application/xml; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$siteUrl = 'https://vnvevents.com';

$cmsRepository = new CmsContentsRepository();
$locationRepository = new LocationPagesRepository();
$forumTopicRepository = new ForumTopicRepository();

$urls = [
    [
        'loc' => $siteUrl . '/',
        'lastmod' => date('Y-m-d'),
        'priority' => '1.0',
    ],
    [
        'loc' => $siteUrl . '/locations/',
        'lastmod' => date('Y-m-d'),
        'priority' => '0.8',
    ],
];

foreach ($locationRepository->getAllIndexablePublished() as $page) {
    $slug = trim((string)($page->slug ?? ''), '/');
    if ($slug === '') {
        continue;
    }

    $urls[] = [
        'loc' => canonical_url($page->canonical_url ?? null, '/' . $slug . '/', $siteUrl),
        'lastmod' => date_value($page->updated_at ?? $page->published_at ?? $page->created_at ?? null),
        'priority' => '0.8',
    ];
}

foreach ($cmsRepository->getPublishedSitemapEntries('en') as $content) {
    $route = $content->route ?? ('/' . trim((string)($content->slug ?? ''), '/') . '/');
    $urls[] = [
        'loc' => canonical_url($content->canonical_url ?? null, $route, $siteUrl),
        'lastmod' => date_value($content->updated_at ?? $content->published_at ?? $content->created_at ?? null),
        'priority' => ($content->type ?? '') === 'post' ? '0.7' : '0.8',
    ];
}

foreach ($forumTopicRepository->getPublishedSitemapEntries() as $topic) {
    $urls[] = [
        'loc' => $siteUrl . '/forums/' . trim((string)$topic->slug, '/') . '/',
        'lastmod' => date_value($topic->updated_at ?? $topic->published_at ?? $topic->created_at ?? null),
        'priority' => '0.6',
    ];
}

$unique = [];
foreach ($urls as $url) {
    $unique[$url['loc']] = $url;
}

$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$urlset = $doc->createElement('urlset');
$urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$doc->appendChild($urlset);

foreach ($unique as $entry) {
    $url = $doc->createElement('url');
    $url->appendChild($doc->createElement('loc', $entry['loc']));
    if (!empty($entry['lastmod'])) {
        $url->appendChild($doc->createElement('lastmod', $entry['lastmod']));
    }
    if (!empty($entry['priority'])) {
        $url->appendChild($doc->createElement('priority', $entry['priority']));
    }
    $urlset->appendChild($url);
}

echo $doc->saveXML();

function canonical_url(?string $stored, string $fallbackPath, string $siteUrl): string
{
    $url = trim((string)$stored);
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $path = $url !== '' ? $url : $fallbackPath;
    return rtrim($siteUrl, '/') . '/' . ltrim($path, '/');
}

function date_value($date): ?string
{
    if (!$date) {
        return null;
    }

    $timestamp = strtotime((string)$date);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}
