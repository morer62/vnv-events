<?php

use App\Repositories\VenueRepository;
use App\Utils\ParamsUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$repo = new VenueRepository();
$params = ParamsUtils::getRouteParams();

$id = $params[0];
$venue = $repo->getFullVenueDetails((int) $id);

if (!$venue || $venue->status !== 'APPROVED') {
    echo TemplateREsponse::renderInTemplates("error.twig", [
        "message" => "Venue not found or not available."
    ]);
    exit;
}

TemplateResponse::renderAndDisplay(__DIR__. "/index.twig", [
    'venue' => $venue
]);