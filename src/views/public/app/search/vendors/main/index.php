<?php

use App\Repositories\ServiceRepository;
use App\Repositories\ServiceCategoriesRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $lat = $_GET['lat'] ?? null;
    $lng = $_GET['lng'] ?? null;
    $category = $_GET['category'] ?? null; // may be array
    $range = $_GET['range'] ?? 25;

    $services = [];
    $related = [];
    $noResults = false;

    $catRepo = new ServiceCategoriesRepository();
    $categories = $catRepo->getAll();

    // Normalize categories to array
    $categoryIds = [];
    if (is_array($category)) {
        $categoryIds = array_values(array_unique(array_map('intval', $category)));
    } elseif (!is_null($category) && $category !== '') {
        $categoryIds = [intval($category)];
    }

    if ($lat && $lng) {
        $repo = new ServiceRepository();

        if (empty($categoryIds)) {
            $services = $repo->searchAllByLocation($lat, $lng, $range);
            $related = [];
        } elseif (count($categoryIds) === 1) {
            $services = $repo->searchByCategoryAndLocation($categoryIds[0], $lat, $lng, $range);
            $related = $repo->searchNearbyDifferentCategories($categoryIds[0], $lat, $lng, $range);
        } else {
            // Multi-category (union). We'll query each and merge unique by id.
            $found = [];
            foreach ($categoryIds as $cid) {
                foreach ($repo->searchByCategoryAndLocation($cid, $lat, $lng, $range) as $row) {
                    $found[$row->id] = $row;
                }
            }
            $services = array_values($found);
            $related = [];
        }

        if (empty($services) && empty($related)) {
            $noResults = true;
        }
    }

     else {
        $repo = new ServiceRepository();
        $services = $repo->getLastApproved();
    }


    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'services' => $services,
        'related' => $related,
        'categories' => $categories,
        'selected_category' => $categoryIds,
        'selected_range' => $range,
        'lat' => $lat,
        'lng' => $lng,
        'base_url' => $_ENV["APP_URL"],
        'no_results' => $noResults,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
