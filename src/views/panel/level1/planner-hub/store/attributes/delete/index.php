<?php

use App\Repositories\Connection;
use App\Repositories\StoreAttributesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $repo = new StoreAttributesRepository();
    $ownerId = AvomealContext::ownerId();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid attribute.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $attribute = $repo->getOne(['id' => $id, 'id_owner' => $ownerId]);

    if (!$attribute) {
        MessageUtil::setMessage("Attribute not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $db = new Connection();

    $db->query("
        SELECT COUNT(*) AS total
        FROM store_attribute_values
        WHERE id_attribute = :id_attribute
          AND id_owner = :id_owner
    ");
    $db->bind(':id_attribute', $id);
    $db->bind(':id_owner', $ownerId);
    $valuesCount = $db->fetchOne();

    if ((int)($valuesCount->total ?? 0) > 0) {
        MessageUtil::setMessage("This attribute cannot be deleted because it already has values associated.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $db->query("
        SELECT COUNT(*) AS total
        FROM store_products_attributes spa
        INNER JOIN store_products sp ON sp.id = spa.id_product
        WHERE spa.id_attribute = :id_attribute
          AND sp.id_owner = :id_owner
    ");
    $db->bind(':id_attribute', $id);
    $db->bind(':id_owner', $ownerId);
    $productsCount = $db->fetchOne();

    if ((int)($productsCount->total ?? 0) > 0) {
        MessageUtil::setMessage("This attribute cannot be deleted because it is already assigned to one or more products.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $ok = $repo->delete(['id' => $id]);

    if (!$ok) {
        MessageUtil::setMessage("Attribute could not be deleted.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    MessageUtil::setMessage("Attribute deleted successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
});

$router->run();
