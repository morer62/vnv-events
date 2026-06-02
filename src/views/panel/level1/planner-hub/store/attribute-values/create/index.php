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
        "attribute" => $attribute
    ]);
});

$router->post(function () {
    $attributeRepo = new StoreAttributesRepository();
    $repo = new StoreAttributeValuesRepository();
    $ownerId = AvomealContext::ownerId();

    $idAttribute = intval($_POST['id_attribute'] ?? 0);
    $value = trim($_POST['value'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $status = trim($_POST['status'] ?? StoreAttributeValuesRepository::STATUS_ACTIVE);

    if ($idAttribute <= 0) {
        MessageUtil::setMessage("Invalid attribute.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $attribute = $attributeRepo->getOne(['id' => $idAttribute, 'id_owner' => $ownerId]);

    if (!$attribute) {
        MessageUtil::setMessage("Attribute not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    if ($value === '') {
        MessageUtil::setMessage("Value is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attribute-values/create?id_attribute=" . $idAttribute);
    }

    $slug = $repo->generateUniqueSlug($idAttribute, $value);

    $ok = $repo->add([
        'id_owner' => $ownerId,
        'id_attribute' => $idAttribute,
        'value' => $value,
        'slug' => $slug,
        'sort_order' => $sortOrder,
        'status' => $status
    ]);

    if (!$ok) {
        MessageUtil::setMessage("Attribute value could not be created.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attribute-values/create?id_attribute=" . $idAttribute);
    }

    MessageUtil::setMessage("Attribute value created successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/attribute-values/home?id_attribute=" . $idAttribute);
});

$router->run();
