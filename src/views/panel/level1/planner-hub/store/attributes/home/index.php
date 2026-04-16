<?php

use App\Repositories\StoreAttributesRepository;
use App\Repositories\StoreAttributeValuesRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $attributesRepo = new StoreAttributesRepository();
    $valuesRepo = new StoreAttributeValuesRepository();

    $attributes = $attributesRepo->getActive();

    foreach ($attributes as $attribute) {
        $allValues = $valuesRepo->getByAttribute((int)$attribute->id);
        $activeValues = $valuesRepo->getActiveByAttribute((int)$attribute->id);

        $attribute->values_count = is_array($allValues) ? count($allValues) : 0;
        $attribute->active_values_count = is_array($activeValues) ? count($activeValues) : 0;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "attributes" => $attributes
    ]);
});

$router->run();