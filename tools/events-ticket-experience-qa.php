<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$views = $root . '/src/views/';
$templates = [
    'panel/level1/event-invitations/index.twig',
    'public/commerce/tickets/index.twig',
    'public/commerce/tickets/success/index.twig',
];
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$loader = new Twig\Loader\FilesystemLoader($views);
$twig = new Twig\Environment($loader);
foreach (['path', 'asset', 'asset_for', 'trans', 'url', 'csrf_token', 'get_csrf'] as $functionName) {
    $twig->addFunction(new Twig\TwigFunction($functionName, static fn (...$arguments): string => '/qa-path'));
}

foreach ($templates as $template) {
    try {
        $source = (string) file_get_contents($views . $template);
        $twig->parse($twig->tokenize(new Twig\Source($source, $template)));
    } catch (Throwable $exception) {
        $failures[] = $template . ' Twig error: ' . $exception->getMessage();
    }
}

try {
    $checkoutHtml = $twig->render('public/commerce/tickets/index.twig', [
        'venueEvent' => (object) [
            'id' => 41,
            'name' => 'VNV QA Event',
            'description' => 'A safe local render test.',
            'start_date' => '2028-06-12 18:30:00',
            'location' => 'Miami, Florida',
        ],
        'ticketTypes' => [
            (object) ['id' => 7, 'name' => 'General Admission', 'description' => 'QA ticket', 'price' => 45.00],
        ],
        'currentStage' => (object) ['name' => 'Early access', 'discount_percentage' => 10],
        'stripe_key' => 'pk_test_qa',
        'user' => ['name' => 'QA User', 'email' => 'qa@example.test'],
    ]);
    $expect(str_contains($checkoutHtml, 'VNV QA Event'), 'Ticket checkout failed its runtime render test.');
    $expect(str_contains($checkoutHtml, '$40.50'), 'Ticket checkout failed to render the discounted price.');
} catch (Throwable $exception) {
    $failures[] = 'Ticket checkout runtime error: ' . $exception->getMessage();
}

try {
    $successHtml = $twig->render('public/commerce/tickets/success/index.twig', [
        'event_name' => 'VNV QA Event',
        'total_amount' => 40.50,
        'current_stage' => (object) ['name' => 'Early access', 'discount_percentage' => 10],
        'ticket_codes' => [['code' => 'QA-TICKET', 'ticket_type' => 'General Admission', 'price' => 40.50]],
        'base_url' => 'https://vnvevents.com',
    ]);
    $expect(str_contains($successHtml, 'QA-TICKET'), 'Ticket success screen failed its runtime render test.');
} catch (Throwable $exception) {
    $failures[] = 'Ticket success runtime error: ' . $exception->getMessage();
}

$admin = (string) file_get_contents($views . $templates[0]);
$checkout = (string) file_get_contents($views . $templates[1]);
$success = (string) file_get_contents($views . $templates[2]);
$sidebar = (string) file_get_contents($views . 'templates/layout/sidebars/1.twig');
$purchase = (string) file_get_contents($views . 'public/commerce/tickets/purchase/index.php');
$mobileNavigation = (string) file_get_contents($views . 'api/mobile/navigation/index.php');

foreach (['VNV Events · Guest Experience', 'Ticketed venues', 'panel/event-invitations/create'] as $marker) {
    $expect(str_contains($admin, $marker), 'Admin event hub marker missing: ' . $marker);
}
foreach (['tickets/purchase', 'tickets-json', 'buyer-json', 'customer-token', 'stripe.createToken', 'Complete secure purchase'] as $marker) {
    $expect(str_contains($checkout, $marker), 'Ticket checkout contract marker missing: ' . $marker);
}
$expect(strlen(trim($checkout)) > 1000, 'The public ticket checkout must not be empty.');
$expect(str_contains($success, 'View my tickets'), 'The success screen must link to the ticket wallet.');
$expect(!str_contains($sidebar, 'panel/manager-scheduling'), 'Manager Scheduling must not remain in the Level 1 sidebar.');
$expect(!str_contains($mobileNavigation, "'key' => 'manager_scheduling'"), 'Manager Scheduling must not remain in Level 1 mobile navigation.');
$expect(str_contains($mobileNavigation, "'key' => 'gourmet_to_go'"), 'Gourmet To Go must be available in Level 1 mobile navigation.');
$expect(substr_count($sidebar, 'panel/planner-hub/store/gourmet-express') === 2, 'Gourmet To Go needs one route matcher and one VNV Events link.');
$expect(str_contains($purchase, "\$_SESSION['event_name'] = \$event->name"), 'Ticket confirmation must retain the event name.');
$expect(str_contains($purchase, '$discountPercentage'), 'The payment endpoint must charge the same active-stage discount shown at checkout.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Events and ticket experience QA OK' . PHP_EOL;
