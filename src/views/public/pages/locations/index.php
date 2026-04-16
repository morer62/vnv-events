<?php

use App\Repositories\LocationPagesRepository;
use App\Utils\TemplateResponse;

$repo = new LocationPagesRepository();
$pages = $repo->getAllPublished();

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'pages' => $pages,
    'show_whatsapp' => true
]);
