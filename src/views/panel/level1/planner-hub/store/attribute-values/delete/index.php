<?php

use App\Repositories\Connection;
use App\Repositories\StoreAttributeValuesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $repo = new StoreAttributeValuesRepository();
    $ownerId = AvomealContext::ownerId();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid attribute value.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $value = $repo->getOne(['id' => $id, 'id_owner' => $ownerId]);

    if (!$value) {
        MessageUtil::setMessage("Attribute value not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $db = new Connection();
    $db->query("
        SELECT COUNT(*) AS total
        FROM store_products_attributes spa
        INNER JOIN store_products sp ON sp.id = spa.id_product
        WHERE spa.id_attribute_value = :id_attribute_value
          AND sp.id_owner = :id_owner
    ");
    $db->bind(':id_attribute_value', $id);
    $db->bind(':id_owner', $ownerId);
    $rel = $db->fetchOne();

    if ((int)($rel->total ?? 0) > 0) {
        MessageUtil::setMessage("This value cannot be deleted because it is already assigned to one or more products.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attribute-values/home?id_attribute=" . $value->id_attribute);
    }

    $ok = $repo->delete(['id' => $id]);

    if (!$ok) {
        MessageUtil::setMessage("Attribute value could not be deleted.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attribute-values/home?id_attribute=" . $value->id_attribute);
    }

    MessageUtil::setMessage("Attribute value deleted successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/attribute-values/home?id_attribute=" . $value->id_attribute);
});

$router->run();
