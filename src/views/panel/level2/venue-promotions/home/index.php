<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueEventsRepository;
use App\Repositories\VenuePromotionsRepository;
use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $venuePromotionsRepository = new VenuePromotionsRepository();
    $venueRepo = new VenueRepository();
    $user = LoginService::getSession();

    $venueId = $_GET["id"];

    $venue = $venueRepo->getOne([
        "id"=> $venueId,
        "user_id"=>$user->getId()
    ]);

    if (is_null($venue)) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal("panel/venues/home");
    }

    $promotion = $venuePromotionsRepository->getAllBy([
       "venue_id" => $venueId
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "data" => $promotion,
        "venue" => $venue
    ]);

});

$router->post(function () {
   $id = $_POST['id'];
   $venueId = $_GET['id'];
   $repo = new VenuePromotionsRepository();

   $promotion = $repo->getOne([
       "id" => $id
   ]);

   if (is_null($promotion)) {
       MessageUtil::setMessage("Promotion not found");
       LocationUtils::redirectInternal('panel/venue-promotions/home?id=' . $venueId);
   }

   FileUtils::removeFile($promotion->image);
   $repo->delete(["id" => $id]);

   MessageUtil::setMessage("Promotion deleted");
   LocationUtils::redirectInternal('panel/venue-promotions/home?id=' . $venueId);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
