<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $nextDeliveryTitle = trim((string)($_ENV['MEAL_PLAN_NEXT_DELIVERY_TITLE'] ?? ''));
    if ($nextDeliveryTitle === '') {
        $nextDeliveryTitle = 'Next Delivery: Wednesday, March 25 & Sunday, March 29';
    }

    $regularDeliveryWindow = trim((string)($_ENV['MEAL_PLAN_REGULAR_DELIVERY_WINDOW'] ?? ''));
    if ($regularDeliveryWindow === '') {
        $regularDeliveryWindow = 'Sunday (8:00 AM to 2:00 PM)';
    }

    $specialDeliveryNote = trim((string)($_ENV['MEAL_PLAN_SPECIAL_DELIVERY_NOTE'] ?? ''));
    if ($specialDeliveryNote === '') {
        $specialDeliveryNote = "We're kicking things off with a special delivery this Wednesday -- don't miss it!";
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'next_delivery_title' => $nextDeliveryTitle,
        'regular_delivery_window' => $regularDeliveryWindow,
        'special_delivery_note' => $specialDeliveryNote
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}