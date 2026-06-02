<?php

use App\Repositories\StoreAttributesRepository;
use App\Repositories\StoreAttributeValuesRepository;
use App\Utils\AvomealContext;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $attributesRepo = new StoreAttributesRepository();
    $valuesRepo = new StoreAttributeValuesRepository();
    $ownerId = AvomealContext::ownerId();

    $attributes = $attributesRepo->getActive($ownerId);

    foreach ($attributes as $attribute) {
        $allValues = $valuesRepo->getByAttributeScoped((int)$attribute->id, $ownerId);
        $activeValues = $valuesRepo->getActiveByAttribute((int)$attribute->id, $ownerId);

        $attribute->values_count = is_array($allValues) ? count($allValues) : 0;
        $attribute->active_values_count = is_array($activeValues) ? count($activeValues) : 0;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "attributes" => $attributes
    ]);
});

$router->run();
