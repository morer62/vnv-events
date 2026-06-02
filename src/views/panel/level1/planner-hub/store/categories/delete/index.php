<?php

use App\Repositories\Connection;
use App\Repositories\StoreCategoriesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    $repo = new StoreCategoriesRepository();
    $ownerId = AvomealContext::ownerId();

    $id = intval($_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid category.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    $category = $repo->getOne(['id' => $id, 'id_owner' => $ownerId]);

    if (!$category) {
        MessageUtil::setMessage("Category not found.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    $db = new Connection();
    $db->query("
        SELECT COUNT(*) AS total
        FROM store_products_categories spc
        INNER JOIN store_products sp ON sp.id = spc.id_product
        WHERE spc.id_category = :id_category
          AND sp.id_owner = :id_owner
    ");
    $db->bind(':id_category', $id);
    $db->bind(':id_owner', $ownerId);
    $rel = $db->fetchOne();

    $totalRelated = (int)($rel->total ?? 0);

    if ($totalRelated > 0) {
        MessageUtil::setMessage("This category cannot be deleted because it is already assigned to one or more products.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    $ok = $repo->delete(['id' => $id]);

    if (!$ok) {
        MessageUtil::setMessage("Category could not be deleted.");
        LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
    }

    MessageUtil::setMessage("Category deleted successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/categories/home");
});

$router->run();
