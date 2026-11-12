<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (empty($_SESSION['quick_request_csrf'])) {
        $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    }

    $programs = require dirname(__DIR__, 5) . '/config/podcasts.php';
    $videos = [];
    foreach ($programs as $program) {
        foreach ($program['episodes'] as $episode) {
            $videos[] = [
                '@type' => 'VideoObject',
                'name' => $episode['official_title'],
                'description' => $episode['description'],
                'thumbnailUrl' => ['https://vnvevents.com/' . $episode['thumbnail']],
                'uploadDate' => date('c', strtotime($episode['published'])),
                'duration' => 'PT' . str_replace(':', 'M', $episode['duration']) . 'S',
                'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . $episode['video_id'],
                'contentUrl' => $episode['youtube_url'],
            ];
        }
    }

    $seo = [
        'title' => 'VNV Podcasts | Local Tech Lab & The Ground Floor',
        'description' => 'Watch every published episode of Local Tech Lab by Tech Lab Miami and The Ground Floor by VNV Events without leaving the VNV podcast catalog.',
        'canonical' => 'https://vnvevents.com/podcasts/',
        'og_image' => 'https://vnvevents.com/assets/images/podcasts/ground-floor-episode-3.webp',
        'og_image_alt' => 'The Ground Floor and Local Tech Lab podcast catalog',
    ];

    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'CollectionPage',
                '@id' => 'https://vnvevents.com/podcasts/#page',
                'url' => $seo['canonical'],
                'name' => $seo['title'],
                'description' => $seo['description'],
                'isPartOf' => ['@id' => 'https://vnvevents.com/#website'],
                'hasPart' => array_map(static fn (array $video): array => ['@id' => $video['contentUrl']], $videos),
            ],
            ...$videos,
        ],
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'programs' => $programs,
        'seo' => $seo,
        'schemaJson' => $schema,
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
