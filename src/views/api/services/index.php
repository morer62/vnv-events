<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServicePhotosRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\ServiceEventsRepository;
use App\Repositories\ServicePromotionsRepository;
use App\Repositories\ServiceZipPaymentsRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Repositories\WordpressSyncOriginsRepository;

Cors::handle();

$router = new Router();

$router->get(function () {
    $categoryRepo = new ServiceCategoriesRepository();
    $photoRepo = new ServicePhotosRepository();
    $repo = new ServiceRepository();
    $eventsRepo = new ServiceEventsRepository();
    $promosRepo = new ServicePromotionsRepository();
    $zipRepo = new ServiceZipPaymentsRepository();

    $limit = $_GET["limit"] ?? 0;

    $items = $repo->getAllBy([
        "status" => "APPROVED"
    ], limit: $limit);

    $categories = $categoryRepo->getAll();

    return JsonResponse::createResponse(array_map(function ($item) use ($categories, $photoRepo, $eventsRepo, $promosRepo, $zipRepo) {

        $cat = null;
        foreach ($categories as $category) {
            if ($category->id == $item->category_id) {
                $cat = $category;
                break;
            }
        }

        $photos = $photoRepo->getAllBy(["service_id" => $item->id]);
        $events = $eventsRepo->getAllBy(["service_id" => $item->id]);
        $promos = $promosRepo->getAllBy(["service_id" => $item->id]);
        $cities = $zipRepo->getAllBy(["id_service" => $item->id]);

        return [
            "id" => $item->id,
            "name" => $item->name,
            "description" => $item->description,
            "address" => $item->address,
            "lat" => $item->lat,
            "lng" => $item->lng,
            "phone_number" => $item->phone_number,
            "email" => $item->email,
            "website" => $item->website,
            "instagram" => $item->instagram,
            "facebook" => $item->facebook,
            "twitter" => $item->twitter,
            "yelp" => $item->yelp,
            "category" => [
                "id" => $cat?->id,
                "name" => $cat?->service_category,
            ],
            "cities" => array_map(function ($c) {
                return [
                    "slug" => $c->city_slug,
                    "display" => $c->city_display,
                    "status" => $c->status,
                ];
            }, $cities),
            "photos" => array_map(function ($photo) {
                return [
                    "id" => $photo->id,
                    "url" => LocationUtils::assetFor($photo->image),
                ];
            }, $photos),
            "events" => array_map(function ($event) {
                return [
                    "id" => $event->id,
                    "start_date" => $event->start_date,
                    "end_date" => $event->end_date,
                    "name" => $event->name,
                    "description" => $event->description,
                    "external_link" => $event->external_link,
                    "url" => LocationUtils::assetFor($event->image),
                ];
            }, $events),
            "promos" => array_map(function ($promo) {
                return [
                    "id" => $promo->id,
                    "start_date" => $promo->start_date,
                    "end_date" => $promo->end_date,
                    "name" => $promo->name,
                    "description" => $promo->description,
                    "url" => LocationUtils::assetFor($promo->image),
                ];
            }, $promos),
        ];
    }, $items));
});

$router->post(function () {
    $input = json_decode(file_get_contents("php://input"), true);

    $origin = $input['origin'] ?? null;
    $category = $input['category'] ?? null; // 'venue' o 'vendor'
    $url = $input['url'] ?? null;
    $entity_id = $input['entity_id'] ?? null;

    if ($origin && $category && $url && $entity_id) {
        $repo = new WordpressSyncOriginsRepository();
        $repo->add([
            "entity_id" => $entity_id,
            "category" => $category,
            "origin" => $origin,
            "url" => $url,
        ]);

        echo json_encode(["status" => "ok"]);
        return;
    }

    echo json_encode(["error" => "Missing parameters"]);
});


$router->run();
