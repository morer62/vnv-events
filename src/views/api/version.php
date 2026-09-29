<?php

use App\Utils\JsonResponse;
use App\Utils\Cors;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    return JsonResponse::createResponse([
        // `version` remains for compatibility with older mobile releases.
        "version" => "4.0.12",
        "current_version" => "4.0.12",
        "minimum_supported_version" => "4.0.11",
        "update_url" => [
            "android" => "https://play.google.com/store/apps/details?id=com.vnvevents.eplannerhub",
            "ios" => "https://apps.apple.com/us/app/vnv-events/id6747983836"
        ]
    ]);
});

$router->run();
