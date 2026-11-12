<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CommercialFinanceService;

$service = new CommercialFinanceService();
$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$corporate = $service->calculateCommission('CORPORATE_FEE', 1000.0);
$assertSame(1000.0, $corporate['eligible_amount'], 'Corporate fee basis');
$assertSame(350.0, $corporate['oriana_amount'], 'Corporate Oriana split');
$assertSame(300.0, $corporate['florida_amount'], 'Corporate Florida split');
$assertSame(350.0, $corporate['vnv_reserve_amount'], 'Corporate reserve split');

$sponsorship = $service->calculateCommission('SPONSORSHIP_FEE', 123.45);
$assertSame(123.45, $sponsorship['eligible_amount'], 'Sponsorship fee basis');
$assertSame($sponsorship['eligible_amount'], round($sponsorship['oriana_amount'] + $sponsorship['florida_amount'] + $sponsorship['vnv_reserve_amount'], 2), 'Rounded split must reconcile');

$original = $service->calculateCommission('ORIGINAL_CONTRIBUTION', 1000.0);
$assertSame(150.0, $original['eligible_amount'], 'Original 15% pool');
$assertSame(52.5, $original['oriana_amount'], 'Original Oriana split');
$assertSame(45.0, $original['florida_amount'], 'Original Florida split');
$assertSame(52.5, $original['vnv_reserve_amount'], 'Original reserve split');

$grant = $service->validateSupportGrant(4.5, 60.0, 80.0, 'Selected catering, staffing and decor support.');
$assertSame(60.0, $grant['discount_food_staff_percent'], 'Food/staff ceiling');
$assertSame(80.0, $grant['discount_decor_percent'], 'Decor ceiling');

foreach ([
    static fn () => $service->calculateCommission('OTHER', 100),
    static fn () => $service->calculateCommission('CORPORATE_FEE', 0),
    static fn () => $service->validateSupportGrant(1, 60.01, 10, 'Scope'),
    static fn () => $service->validateSupportGrant(1, 10, 80.01, 'Scope'),
    static fn () => $service->validateSupportGrant(1, 10, 10, ''),
] as $invalid) {
    $thrown = false;
    try {
        $invalid();
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    $assertSame(true, $thrown, 'Invalid financial input must be rejected');
}

echo "Commercial finance rules OK: {$assertions} assertions\n";
