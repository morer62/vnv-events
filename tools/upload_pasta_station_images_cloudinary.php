<?php

declare(strict_types=1);

use App\Utils\FileUtils;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$folder = 'ophyra-growth-hub/vnvevents/service-photos';
$images = [
    'pasta-station-1' => $root . '/public/assets/images/service_photos/pasta_station_1.webp',
    'pasta-station-2-lite' => $root . '/public/assets/images/service_photos/pasta_station_2_lite.webp',
];

foreach ($images as $publicId => $path) {
    $url = FileUtils::saveFileFromPath($path, $folder, $publicId);
    if (!str_starts_with($url, 'https://res.cloudinary.com/')) {
        throw new RuntimeException("Cloudinary did not return a secure URL for {$publicId}");
    }
    echo $publicId . "\t" . $url . "\n";
}
