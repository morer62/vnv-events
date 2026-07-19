<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\EventExecutionService;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

try {
    $count = (new EventExecutionService())->purgeExpiredPhotos(250);
    fwrite(STDOUT, "Purged {$count} expired event photo(s).\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Event photo cleanup failed: {$e->getMessage()}\n");
    exit(1);
}
