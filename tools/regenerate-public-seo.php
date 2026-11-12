<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SeoFilesGeneratorService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

$result = (new SeoFilesGeneratorService($root . '/public'))->generate('all');
if (($result['status'] ?? 'failed') !== 'success') {
    fwrite(STDERR, 'SEO regeneration did not complete successfully: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo 'Public SEO files regenerated (' . (int)($result['items_count'] ?? 0) . " items).\n";
