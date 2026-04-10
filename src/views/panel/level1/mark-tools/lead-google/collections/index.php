<?php

use App\Repositories\LeadsCollectionsRepository;
use App\Repositories\LeadsCollectionsItemsRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;

$repo = new LeadsCollectionsRepository();
$itemsRepo = new LeadsCollectionsItemsRepository();
$user = LoginService::getSession();

// Crear nueva colección
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["name"])) {
    $name = trim($_POST["name"]);
    if ($name !== "") {
        $repo->add([
            "name" => $name,
            "id_owner" => $user->getId()
        ]);
        MessageUtil::setMessage("Colección creada correctamente.");
    }
    LocationUtils::reload();
}

// Eliminar colección (por query param ?delete=id) 
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_collection"])) {
    $id = (int) $_POST["delete_collection"];

    $repo->delete(["id" => $id]);
 
    MessageUtil::setMessage("Colección eliminada.");
    LocationUtils::reload();
    
}


// Obtener colecciones con conteo de leads
//$collections = $repo->getAllSortedByName();
$collections = $repo->getAllWithItemCount();

 

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    "collections" => $collections, 
]);
