<?php

use App\Repositories\StoreAttributesRepository;
use App\Utils\AvomealContext;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", []);
});

$router->post(function () {
    $repo = new StoreAttributesRepository();
    $ownerId = AvomealContext::ownerId();

    $name = trim($_POST['name'] ?? '');
    $status = trim($_POST['status'] ?? StoreAttributesRepository::STATUS_ACTIVE);

    if ($name === '') {
        MessageUtil::setMessage("Attribute name is required.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/create");
    }

    $slug = $repo->generateUniqueSlug($name);

    $ok = $repo->add([
        'id_owner' => $ownerId,
        'name' => $name,
        'slug' => $slug,
        'status' => $status
    ]);

    if (!$ok) {
        MessageUtil::setMessage("Attribute could not be created.");
        LocationUtils::redirectInternal("panel/planner-hub/store/attributes/create");
    }

    MessageUtil::setMessage("Attribute created successfully.");
    LocationUtils::redirectInternal("panel/planner-hub/store/attributes/home");
});

$router->run();
