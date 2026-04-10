<?php

use App\Repositories\Connection;
use App\Repositories\StoreAttributeValuesRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $repo = new StoreAttributeValuesRepository();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid attribute value.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $value = $repo->getOne(['id' => $id]);

    if (!$value) {
        MessageUtil::setMessage("Attribute value not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    $db = new Connection();
    $db->query("
        SELECT COUNT(*) AS total
        FROM store_products_attributes
        WHERE id_attribute_value = :id_attribute_value
    ");
    $db->bind(':id_attribute_value', $id);
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