<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenuePhotosRepository;
use App\Repositories\VenueRepository;
use App\Repositories\VenueEventsRepository;
use App\Repositories\VenuePromotionsRepository;
use App\Repositories\VenueAvailabilityRepository;
use App\Repositories\VenueAmenitiesRepository;
use App\Repositories\VenueServicesRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $categoryRepo = new VenueCategoriesRepository();
    $photoRepo = new VenuePhotosRepository();
    $venueRepo = new VenueRepository();
    $eventsRepo = new VenueEventsRepository();
    $promosRepo = new VenuePromotionsRepository();
    $availabilityRepo = new VenueAvailabilityRepository();
    $amenitiesRepo = new VenueAmenitiesRepository();
    $servicesRepo = new VenueServicesRepository();

    $limit = $_GET["limit"] ?? 0;

    $items = $venueRepo->getAllBy(["status" => "APPROVED"], limit: $limit);
    $categories = $categoryRepo->getAll();

    return JsonResponse::createResponse(array_map(function ($item) use ($categories, $photoRepo, $eventsRepo, $promosRepo, $availabilityRepo, $amenitiesRepo, $servicesRepo) {
        $cat = null;
        foreach ($categories as $category) {
            if ($category->id == $item->category_id) {
                $cat = $category;
                break;
            }
        }

        $photos = $photoRepo->getAllBy(["venue_id" => $item->id]);
        $events = $eventsRepo->getAllBy(["venue_id" => $item->id]);
        $promos = $promosRepo->getAllBy(["venue_id" => $item->id]);
        $availability = $availabilityRepo->getAllBy(["venue_id" => $item->id]);
        $amenities = $amenitiesRepo->getAllBy(["venue_id" => $item->id]);
        $includedServices = $servicesRepo->getAllBy(["venue_id" => $item->id]);

        return [
            "id" => $item->id,
            "name" => $item->name,
            "description" => $item->description,
            "address" => $item->address,
            "lat" => $item->lat,
            "lng" => $item->lng,
            "wordpress_profile" => $item->wordpress_profile,
            "status" => $item->status,
            "phone_number" => $item->phone_number,
            "email" => $item->email,
            "website" => $item->website,
            "instagram" => $item->instagram,
            "facebook" => $item->facebook,
            "twitter" => $item->twitter,
            "yelp" => $item->yelp,
            "business_name" => $item->business_name,
            "ein" => $item->ein,
            "capacity" => $item->capacity,
            "base_price" => (float) $item->base_price,
            "indoor_outdoor_type" => $item->indoor_outdoor_type,
            "category" => [
                "id" => $cat?->id,
                "name" => $cat?->name,
                "description" => $cat?->description
            ],
            "availability" => array_map(function ($a) {
                return [
                    "day" => $a->day,
                    "enabled" => $a->is_closed == 0,
                    "from" => $a->opens,
                    "to" => $a->closes
                ];
            }, $availability),
            "amenities" => array_map(fn($a) => $a->amenity, $amenities),
            "included_services" => array_map(fn($s) => $s->service, $includedServices),
            "photos" => array_map(fn($p) => [
                "id" => $p->id,
                "url" => LocationUtils::assetFor($p->image)
            ], $photos),
            "events" => array_map(fn($e) => [
                "id" => $e->id,
                "start_date" => $e->start_date,
                "end_date" => $e->end_date,
                "name" => $e->name,
                "description" => $e->description,
                "external_link" => $e->external_link,
                "url" => LocationUtils::assetFor($e->image)
            ], $events),
            "promos" => array_map(fn($p) => [
                "id" => $p->id,
                "start_date" => $p->start_date,
                "end_date" => $p->end_date,
                "name" => $p->name,
                "description" => $p->description,
                "url" => LocationUtils::assetFor($p->image)
            ], $promos)
        ];
    }, $items));
});

$router->run();
