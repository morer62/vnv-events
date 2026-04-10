<?php

use App\Repositories\VenueRepository;
use App\Repositories\VenueCategoriesRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $lat = $_GET['lat'] ?? null;
    $lng = $_GET['lng'] ?? null;
    $category = $_GET['category'] ?? null;
    $range = $_GET['range'] ?? 25;

    // Normalizar categorías a array de enteros si viene category[]
    $categoryIds = [];
    if (is_array($category)) {
        $categoryIds = array_values(array_unique(array_map('intval', $category)));
    } elseif (!is_null($category) && $category !== '') {
        $categoryIds = [intval($category)];
    }

    $venues = [];
    $related = [];
    $farVenues = [];
    $noResults = false;

    if ($lat && $lng) {
        $repo = new VenueRepository();
        $venues = $repo->searchByCategoriesAndLocation($categoryIds, $lat, $lng, $range);
        $related = $repo->searchNearbyDifferentCategoriesMulti($categoryIds, $lat, $lng, $range);

        if (empty($venues)) {
            $farVenues = $repo->searchByCategoriesAndLocation($categoryIds, $lat, $lng, 100);
        }

        if (empty($venues) && empty($related) && empty($farVenues)) {
            $noResults = true;
        }
    }

     else {
        $repo = new VenueRepository();
        $venues = $repo->getLastApproved();
    }

    $catRepo = new VenueCategoriesRepository();
    $categories = $catRepo->getAll();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'venues' => $venues,
        'related' => $related,
        'farVenues' => $farVenues,
        'categories' => $categories,
        'selected_category' => $categoryIds,
        'selected_range' => $range,
        'lat' => $lat,
        'lng' => $lng,
        'base_url' =>  $_ENV["APP_URL"],
        'no_results' => $noResults,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
