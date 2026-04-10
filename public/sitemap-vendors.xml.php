<?php

use App\Repositories\ServiceRepository;
use App\Utils\LocationUtils;

// Set the correct Content-Type header for XML
header('Content-Type: application/xml; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$vendorsRepository = new ServiceRepository();
$vendors = $vendorsRepository->getAllByActiveStatus();

$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$urlset = $doc->createElement("urlset");
$urlset->setAttribute("xmlns", "http://www.sitemaps.org/schemas/sitemap/0.9");
$doc->appendChild($urlset);

foreach ($vendors as $vendor) {
    $url = $doc->createElement("url");
    $loc = $doc->createElement("loc", LocationUtils::pathFor('profiles/vendor/' . $vendor->id . '/details') );
    $url->appendChild($loc);
    $urlset->appendChild($url);
}
// how to output the xml inline
echo $doc->saveXML();