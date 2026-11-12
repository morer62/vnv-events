<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$loader = new FilesystemLoader(dirname(__DIR__) . '/src/views');
$twig = new Environment($loader, ['cache' => false, 'strict_variables' => false]);
foreach (['path', 'asset_for', 'trans'] as $function) {
    $twig->addFunction(new TwigFunction($function, static fn (...$arguments) => (string)($arguments[0] ?? '')));
}

$templates = [
    'public/pages/nonprofit-partnerships/index.twig',
    'public/pages/sponsor-activation-production/index.twig',
    'public/pages/event-production/index.twig',
    'public/pages/corporate-events/index.twig',
    'public/pages/vnv-events-productions/index.twig',
    'public/pages/vnv-events-productions/retro-glam/index.twig',
    'public/pages/vnv-events-productions/mommy-and-daddy-break/index.twig',
    'public/app/planner-hub/index.twig',
    'panel/level1/planner-hub/management/crm/partnerships/index.twig',
    'templates/components/publicEventRequestModal.html.twig',
    'templates/components/publicQuickRequestModal.html.twig',
    'templates/components/publicNonprofitStories.html.twig',
    'templates/components/publicSponsorRequestModal.html.twig',
    'templates/layout/publicHeaderVnv.html.twig',
];

foreach ($templates as $template) {
    $twig->load($template);
}

echo 'Commercial partnership Twig compile OK: ' . count($templates) . " templates\n";
