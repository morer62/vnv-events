<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$sidebarPath = 'templates/layout/sidebars/1.twig';
$gourmetPath = 'panel/level1/planner-hub/store/gourmet-express/index.twig';
$sidebar = (string)file_get_contents($root . '/src/views/' . $sidebarPath);
$gourmet = (string)file_get_contents($root . '/src/views/' . $gourmetPath);
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(substr_count($sidebar, 'data-bs-parent="#sidebar"') === 4, 'The Level 1 sidebar must expose four accordion groups.');
$expect(str_contains($sidebar, "{% set level1_section = 'vnv-events' %}"), 'VNV Events must be the fallback group.');

foreach ([
    'panel/home',
    'panel/planner-hub/management/orders/orders/execution',
    'panel/planner-hub/management/invoices',
    'panel/planner-hub/management/orders/calendar',
    'panel/planner-hub/management/orders/contracts',
    'panel/planner-hub/management/users?active_tab=clients',
    'panel/planner-hub/management/users',
    'panel/planner-hub/management/crm',
    'panel/planner-hub/team/chat',
    'panel/cms/pages/create',
    'panel/cms',
    'panel/event-invitations',
    'panel/planner-hub/store/products/home',
    'panel/planner-hub/store/gourmet-express',
    'panel/planner-hub/settings/automation',
    'panel/settings',
    'panel/planner-hub/settings/payment-providers',
    'panel/planner-hub/settings/smtp',
] as $route) {
    $expect(str_contains($sidebar, $route), 'Sidebar route missing: ' . $route);
}

foreach (['panel/manager-scheduling', 'panel/agents', 'panel/social-media-agent', 'panel/multimedia-sessions', 'panel/forum'] as $hiddenRoute) {
    $expect(!str_contains($sidebar, $hiddenRoute), 'Externalized operation must not remain in the sidebar: ' . $hiddenRoute);
}

foreach ([
    'panel/planner-hub/store/orders/home',
    'panel/planner-hub/store/carts/home',
    'panel/planner-hub/store/subscriptions/home',
    'panel/planner-hub/store/coupons/home',
    'panel/planner-hub/store/payments/home',
] as $gourmetRoute) {
    $expect(str_contains($gourmet, $gourmetRoute), 'Gourmet To Go hub route missing: ' . $gourmetRoute);
}

$loader = new Twig\Loader\FilesystemLoader($root . '/src/views');
$twig = new Twig\Environment($loader);
foreach (['path', 'asset', 'asset_for', 'trans', 'url', 'csrf_token', 'get_csrf'] as $functionName) {
    $twig->addFunction(new Twig\TwigFunction($functionName, static fn (...$arguments): string => ''));
}

foreach ([$sidebarPath, $gourmetPath, 'panel/level1/planner-hub/store/products/home/index.twig'] as $templatePath) {
    try {
        $source = (string)file_get_contents($root . '/src/views/' . $templatePath);
        $twig->parse($twig->tokenize(new Twig\Source($source, $templatePath)));
    } catch (Throwable $exception) {
        $failures[] = $templatePath . ' Twig error: ' . $exception->getMessage();
    }
}

foreach ([
    'https://vnvevents.com/panel/home/' => 'level1-vnv-events',
    'https://vnvevents.com/panel/cms/pages/create/' => 'level1-publishing',
    'https://vnvevents.com/panel/planner-hub/store/gourmet-express/' => 'level1-vnv-events',
    'https://vnvevents.com/panel/planner-hub/store/products/home/' => 'level1-store-events',
    'https://vnvevents.com/panel/planner-hub/settings/payment-providers/' => 'level1-settings',
] as $url => $expectedOpenGroup) {
    try {
        $renderedSidebar = $twig->render($sidebarPath, ['current_canonical_url' => $url]);
        $expect(substr_count($renderedSidebar, 'collapse show') === 1, $url . ' must open exactly one group.');
        $expect(
            str_contains($renderedSidebar, 'id="' . $expectedOpenGroup . '" class="sidebar-dropdown list-unstyled collapse show"'),
            $url . ' must keep ' . $expectedOpenGroup . ' open.'
        );
    } catch (Throwable $exception) {
        $failures[] = $url . ' rendered navigation error: ' . $exception->getMessage();
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Level 1 sidebar navigation QA OK' . PHP_EOL;
