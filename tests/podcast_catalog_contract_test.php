<?php

$root = dirname(__DIR__);
$programs = require $root . '/config/podcasts.php';
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(array_keys($programs) === ['local-tech-lab', 'the-ground-floor'], 'Podcast programs or their approved order changed.');
$assert(count($programs['local-tech-lab']['episodes']) === 2, 'Local Tech Lab must contain its two published full episodes.');
$assert(count($programs['the-ground-floor']['episodes']) === 3, 'The Ground Floor must contain its three numbered episodes.');

$ids = [];
foreach ($programs as $program) {
    $assert(is_file($root . '/public/' . $program['logo']), 'Missing verified program logo: ' . $program['name']);
    foreach ($program['episodes'] as $episode) {
        $ids[] = $episode['video_id'];
        $assert((bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $episode['video_id']), 'Invalid YouTube video ID.');
        $assert($episode['youtube_url'] === 'https://www.youtube.com/watch?v=' . $episode['video_id'], 'Original episode URL does not match its video ID.');
        $assert(is_file($root . '/public/' . $episode['thumbnail']), 'Missing official episode thumbnail: ' . $episode['title']);
        $assert($episode['description'] !== '' && $episode['credit'] !== '', 'Episode metadata is incomplete: ' . $episode['title']);
    }
}

$assert($ids === ['tLEGR2qboDU', '6GU7zrO8KQM', '-_yJ2EcitEA', 'piU0RpOPbN0', '_Yk0_-Zwn9k'], 'The verified episode inventory changed.');
foreach (['l0y0GEIMzKQ', 'HcRvyQYdQW0', 'xiUpYFdM32s', 'TGrNFVTm-KU', 'opoXLe5LyFk', 'n-tX3jTDeeU', 'ir4WTpXZIOE', 'wZFcHcr3XHI'] as $nonEpisodeId) {
    $assert(!in_array($nonEpisodeId, $ids, true), 'A Local AI video, short, or non-episode playlist item entered the catalog: ' . $nonEpisodeId);
}

$template = file_get_contents($root . '/src/views/public/pages/podcasts/index.twig');
foreach (['data-podcast-open', 'podcastEpisodeModal', 'data-vnv-open-quick-request', 'publicQuickRequestModal.html.twig'] as $needle) {
    $assert(str_contains($template, $needle), 'Podcast page contract is missing: ' . $needle);
}
$player = file_get_contents($root . '/public/assets/js/podcasts.js');
$assert(str_contains($player, 'youtube-nocookie.com/embed/'), 'Podcast player does not use the privacy-enhanced YouTube embed.');
$assert(substr_count(strtolower($template), '<h1') === 1, 'Podcast catalog must render exactly one H1.');

$homepage = file_get_contents($root . '/src/views/public/app/planner-hub/index.twig');
$assert(str_contains($homepage, 'Local Tech Lab & The Ground Floor'), 'Homepage does not introduce both podcast programs.');
$assert(str_contains($homepage, "path('/podcasts')"), 'Homepage is missing the podcast catalog link.');
$assert(str_contains($homepage, 'PL8hdMpm11FxmEz_1IrnoN3DpbOoMHtFkP'), 'DJ Sessions playlist was modified or removed.');

$footer = file_get_contents($root . '/src/views/templates/layout/publicFooterVnv.html.twig');
$assert(str_contains($footer, "path('/podcasts')"), 'Podcast catalog is missing from the public footer.');

echo "Podcast catalog contract assertions passed: {$assertions}\n";
