<?php

use App\Repositories\StoreAttributesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

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

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "attribute" => $attribute
    ]);
});

$router->post(function () {
    $repo = new StoreAttributesRepository();
    $ownerId = AvomealContext::ownerId();

    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = trim($_POST['status'] ?? StoreAttributesRepository::STATUS_ACTIVE);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid attribute.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
    }

    if ($name === '') {
        MessageUtil::setMessage("Attribute name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/edit?id=" . $id);
    }

    $slug = $repo->generateUniqueSlug($name);

    $repo->update([
        'id_owner' => $ownerId,
        'name' => $name,
        'slug' => $slug,
        'status' => $status
    ], [
        'id' => $id,
        'id_owner' => $ownerId
    ]);

    MessageUtil::setMessage("Attribute updated successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
});

$router->run();
