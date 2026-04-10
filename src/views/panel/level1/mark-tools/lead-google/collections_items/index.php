<?php

use App\Repositories\LeadsCollectionsRepository;
use App\Repositories\LeadsCollectionsItemsRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\TemplateResponse;

$itemsRepo = new LeadsCollectionsItemsRepository();
$collectionsRepo = new LeadsCollectionsRepository();

$user = LoginService::getSession();

// Validar collection_id via GET
$collectionId = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!$collectionId) {
    MessageUtil::setMessage('Colección no encontrada.', 'danger');
    LocationUtils::reload();
}

// Obtener colección (opcional)
$collection = $collectionsRepo->getOne(['id' => $collectionId]);
if (!$collection) {
    MessageUtil::setMessage('Colección no encontrada.', 'danger');
    LocationUtils::reload();
}

// Acción de eliminación de un lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $itemId = (int)$_POST['delete_item'];
    $itemsRepo->delete(['id' => $itemId]);
    MessageUtil::setMessage('Lead eliminado correctamente.');
    LocationUtils::reload();
}

// Exportar CSV vía POST
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["export_csv"])) {
    $collectionIdExport = (int) $_POST["collection_id"];
    $exportItems = $itemsRepo->getAllBy(["collection_id" => $collectionIdExport]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads_collection_' . $collectionIdExport . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Phone', 'Email', 'Website', 'Address']);

    foreach ($exportItems as $item) {
        fputcsv($output, [
            $item->name,
            $item->phone,
            $item->email,
            $item->website,
            $item->address
        ]);
    }

    fclose($output);
    exit;
}


// Listar leads de la colección
$items = $itemsRepo->getAllBy(['collection_id' => $collectionId]);

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'collection' => $collection,
    'items' => $items, 
]);
