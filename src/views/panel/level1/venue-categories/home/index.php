<?php

use App\Repositories\VenueCategoriesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $venueCategoryRepo = new VenueCategoriesRepository();
    $categories = $venueCategoryRepo->getAll();



    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categories,
    ]);
});

$router->post(function () {
   $id = $_POST['id'];
   $repo = new VenueCategoriesRepository();

   $cat = $repo->getOne([
       "id" => $id
   ]);

   if (is_null($cat)) {
       MessageUtil::setMessage("Category not found");
       LocationUtils::redirectInternal('panel/venue-categories/home');
   }

   $repo->delete([
       "id" => $id
   ]);
   MessageUtil::setMessage("Category deleted");
   LocationUtils::redirectInternal('panel/venue-categories/home');
});
try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
