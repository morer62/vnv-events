<?php

use App\Repositories\ServiceRepository;
use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\PlacesUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

// GET: cargar servicio existente
$router->get(function () {
    $user = LoginService::getSession();
    $serviceRepo = new ServiceRepository();
    $categoriesRepo = new ServiceCategoriesRepository();

    $id = $_GET['id'] ?? null;
    if (!$id) {
        MessageUtil::setMessage("Service ID not provided.");
        LocationUtils::redirectInternal("panel/service/home");
    }

    $service = $serviceRepo->getOne([
        'id' => $id,
        'user_id' => $user->getId()
    ]);

    if (!$service) {
        MessageUtil::setMessage("Service not found.");
        LocationUtils::redirectInternal("panel/service/home");
    }

    // Cargar subcategorías asignadas para pre-chequear
    $db = new Connection();
    $db->query("SELECT category_id FROM service_categories_assigned WHERE service_id = :sid");
    $db->bind(":sid", (int)$id);
    $assignedRows = $db->fetchAll();
    $assignedCategoryIds = array_map(function($r){ return is_object($r) ? (int)$r->category_id : (int)$r['category_id']; }, $assignedRows ?: []);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "service" => $service,
        "categories" => $categoriesRepo->getAll(),
        "assignedCategoryIds" => $assignedCategoryIds
    ]);
});

// POST: actualizar servicio
$router->post(function () {
    $user = LoginService::getSession();
    $serviceRepo = new ServiceRepository();

    $id = $_GET['id'] ?? null;
    if (!$id) {
        MessageUtil::setMessage("Service ID missing.");
        LocationUtils::redirectInternal("panel/service/home");
    }

    try {
        [$lat, $lng] = PlacesUtils::getCoordinates($_POST["address"]);
    } catch (Exception) {
        [$lat, $lng] = [null, null];
    }

    $serviceRepo->update([
        "name" => $_POST["name"],
        "description" => $_POST["description"],
        "address" => $_POST["address"],
        "phone_number" => $_POST["phone_number"],
        "email" => $_POST["email"],
        "website" => $_POST["website"],
        "instagram" => $_POST["instagram"],
        "facebook" => $_POST["facebook"],
        "twitter" => $_POST["twitter"],
        "yelp" => $_POST["yelp"],
        "lat" => $lat,
        "lng" => $lng,
        "category_id" => $_POST["category_id"]
    ], [
        "id" => $id,
        "user_id" => $user->getId()
    ]);

    // Sincronizar subcategorías seleccionadas en checklist
    try {
        $selected = $_POST['extra_categories'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }
        // Normalizar a enteros únicos
        $selected = array_values(array_unique(array_map('intval', $selected)));

        $db = new Connection();

        // Estrategia "solo por desmarcar":
        // 1) Insertar marcadas (si faltan)
        foreach ($selected as $cid) {
            $db->query("INSERT IGNORE INTO service_categories_assigned (user_id, service_id, category_id, created_at) VALUES (:uid, :sid, :cid, NOW())");
            $db->bind(":uid", (int)$user->getId());
            $db->bind(":sid", (int)$id);
            $db->bind(":cid", (int)$cid);
            $db->execute();
        }

        // 2) Eliminar solo las que fueron explícitamente desmarcadas
        // Obtenemos las actuales
        $db->query("SELECT category_id FROM service_categories_assigned WHERE service_id = :sid");
        $db->bind(":sid", (int)$id);
        $currentRows = $db->fetchAll();
        $current = array_map(function($r){ return is_object($r) ? (int)$r->category_id : (int)$r['category_id']; }, $currentRows ?: []);
        $toDelete = array_values(array_diff($current, $selected));
        if (count($toDelete) > 0) {
            $named = [];
            foreach ($toDelete as $idx => $cid) { $named[] = ":d$idx"; }
            $inClause = implode(',', $named);
            $sql = "DELETE FROM service_categories_assigned WHERE service_id = :sid AND category_id IN ($inClause)";
            $db->query($sql);
            $db->bind(":sid", (int)$id);
            foreach ($toDelete as $idx => $cid) { $db->bind(":d$idx", (int)$cid); }
            $db->execute();
        }
    } catch (\Throwable $e) {
        MessageUtil::setMessage("There was an error updating categories: " . $e->getMessage());
        LocationUtils::redirectInternal("panel/service/edit?id=" . urlencode((string)$id));
    }

    MessageUtil::setMessage("Service updated successfully.");
    LocationUtils::redirectInternal("panel/service/home");
});

$router->run();
