<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\WordpressSyncOriginsRepository;
use App\Repositories\ClientsRequestRepository;


$router = new Router();

$router->get(function () {
    $venueCategoryRepository = new VenueCategoriesRepository();
    $venueRepository = new VenueRepository();
    $syncRepo = new WordpressSyncOriginsRepository();
    $user = LoginService::getSession();

    $categories = $venueCategoryRepository->getAll();
    $venues = $venueRepository->getAllWithPaymentStatusByUser($user->getId());
    $requestRepo = new ClientsRequestRepository();

        foreach ($venues as $venue) {
            $origins = $syncRepo->getAllBy([
                "category" => "venue",
                "entity_id" => $venue->id
            ]);
            $venue->sync_origins = $origins;

            // Contar client requests
            $venue->request_count = count($requestRepo->getAllBy([
                "profile_cat" => "venue",
                "profile_id" => $venue->id
            ]));
        }


    // 🔗 Traer origen de sincronización por venue
    $originsByVenue = [];
     
    foreach ($venues as $venue) {
        $origins = $syncRepo->getAllBy([
            "category" => "venue",
            "entity_id" => $venue->id
        ]);
        $venue->sync_origins = $origins;
        $originsByVenue[$venue->id] = $origins;
    }


    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categories,
        "venues" => $venues,
        "listing_origins" => $originsByVenue,
        "VENUE_PAYMENT_AMOUNT" => $_ENV["VENUE_PAYMENT_AMOUNT"],
        "venuePrice" => $_ENV["VENUE_PAYMENT_AMOUNT"],
        "venuePriceDiscount" => $_ENV["VENUE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"],
        "hasVenueDiscount" => $user->hasActivePaidMembership(),
        "canCreateVenue" => count($venues) === 0,
        'base_url' =>  $_ENV["APP_URL"],
    ]);
});

$router->post(function () {
    $id = $_POST['id'];
    $repo = new VenueRepository();

    $cat = $repo->getOne([
        "id" => $id
    ]);

    if (is_null($cat)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal('panel/venues/home');
    }

    $repo->delete([
        "id" => $id
    ]);
    MessageUtil::setMessage("Venue deleted");
    LocationUtils::redirectInternal('panel/venues/home');
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
