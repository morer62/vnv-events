<?php

use App\Repositories\StoreAttributesRepository;
use App\Repositories\StoreAttributeValuesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {

    $attributeRepo = new StoreAttributesRepository();
    $valuesRepo = new StoreAttributeValuesRepository();
    $ownerId = AvomealContext::ownerId();

    $idAttribute = intval($_GET['id_attribute'] ?? 0);

    if ($idAttribute <= 0) {
        MessageUtil::setMessage("Invalid attribute.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $attribute = $attributeRepo->getOne(['id' => $idAttribute, 'id_owner' => $ownerId]);

    if (!$attribute) {
        MessageUtil::setMessage("Attribute not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "attribute" => $attribute,
        "values" => $valuesRepo->getByAttributeScoped($idAttribute, $ownerId)
    ]);
});

$router->run();
