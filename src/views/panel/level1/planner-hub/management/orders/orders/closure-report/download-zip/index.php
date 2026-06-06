<?php

use App\Repositories\OrdersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\OrdersTeamTaskPhotosRepository;
use App\Repositories\OrdersTeamOrderPhotosRepository;
use App\Repositories\OrdersClosureCommunicationsRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$user = LoginService::getSession();
if (!$user) {
    MessageUtil::setMessage("Access denied", "Error", "error");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/previous");
    return;
}

$orderRepo = new OrdersRepository();
$orderId = (int)($_GET["id"] ?? 0);

if (!$orderId) {
    MessageUtil::setMessage("Order ID is required", "Error", "error");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/previous");
    return;
}

if ($user->getLevel() === 4) {
    $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
    if ($currentInstitutionId) {
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        $order = $institution && $institution->id_owner
            ? $orderRepo->getOneByIdAndOwner($orderId, $institution->id_owner)
            : null;
    } else {
        $order = null;
    }
} else {
    $order = $orderRepo->getOne(["id" => $orderId]);
    if ($order && $order->id_user != $user->getId() && $order->id_owner != $user->getOwner()) {
        $order = null;
    }
}

if (!$order) {
    MessageUtil::setMessage("Order not found or access denied", "Error", "error");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/previous");
    return;
}

$docRepo = new DocumentsLogsRepository();
$acceptanceRepo = new OrdersAcceptanceContractsRepository();
$suborderRepo = new OrdersSuborderRepository();
$teamTaskRepo = new OrdersTeamTasksRepository();
$photosRepo = new OrdersTeamTaskPhotosRepository();
$teamOrderPhotosRepo = new OrdersTeamOrderPhotosRepository();
$communicationsRepo = new OrdersClosureCommunicationsRepository();

$filesToZip = [];

$allDocs = $docRepo->getAllByOrder($orderId);
$countByType = [];
foreach ($allDocs as $doc) {
    if (empty($doc->file_path)) {
        continue;
    }
    $type = trim($doc->doc_type ?? 'document');
    $countByType[$type] = ($countByType[$type] ?? 0) + 1;
    $n = $countByType[$type];
    $ext = pathinfo(parse_url($doc->file_path, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'pdf';

    if (in_array($type, ['contract_signed', 'order_acceptance_signed'], true)) {
        $folder = '01_contratos';
        $label = $type === 'contract_signed' ? 'contrato_firmado' : 'aceptacion_orden_firmada';
    } elseif (in_array($type, ['pay_first', 'pay_second', 'pay_full', 'payment_receipt', 'sub_pay_first', 'sub_pay_second', 'sub_pay_full'], true)) {
        $folder = '02_recibos_pago';
        $label = str_replace(['pay_', 'sub_', 'payment_receipt'], ['pago_', 'suborden_', 'recibo'], $type);
    } elseif ($type === 'tip_receipt') {
        $folder = '03_propinas';
        $label = 'propina';
    } else {
        $folder = '04_otros_documentos';
        $label = preg_replace('/[^a-zA-Z0-9_-]/', '_', $type);
    }
    $zipPath = "{$folder}/{$label}_{$n}.{$ext}";
    $filesToZip[$zipPath] = $doc->file_path;
}

$acceptanceContracts = $acceptanceRepo->getAllByOrder($orderId);
foreach ($acceptanceContracts as $i => $acc) {
    if (!empty($acc->file_path)) {
        $ext = pathinfo(parse_url($acc->file_path, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'pdf';
        $zipPath = "01_contratos/aceptacion_contrato_" . ($i + 1) . ".{$ext}";
        $filesToZip[$zipPath] = $acc->file_path;
    }
}

$communications = $communicationsRepo->getAllByOrder($orderId);
foreach ($communications as $i => $comm) {
    if (!empty($comm->photo_path)) {
        $ext = pathinfo(parse_url($comm->photo_path, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $filesToZip["05_comunicaciones_cliente/comunicacion_" . ($i + 1) . ".{$ext}"] = $comm->photo_path;
    }
}

$teamOrderPhotos = $teamOrderPhotosRepo->getByOrder($orderId);
$teamPhotoIndex = 0;
foreach ($teamOrderPhotos as $p) {
    if (!empty($p->photo_url)) {
        $teamPhotoIndex++;
        $ext = pathinfo(parse_url($p->photo_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $filesToZip["05b_fotos_equipo/foto_equipo_{$teamPhotoIndex}.{$ext}"] = $p->photo_url;
    }
}

$teamTasks = $teamTaskRepo->getAllWithoutOwner(["id_order" => $orderId]);
$suborders = $suborderRepo->getByOrder($orderId);
foreach ($suborders as $sub) {
    $subTasks = $teamTaskRepo->getAllWithoutOwner(["id_suborder" => $sub->id]);
    $teamTasks = array_merge($teamTasks, $subTasks);
}
$photoIndex = 0;
foreach ($teamTasks as $task) {
    $taskPhotos = $photosRepo->getByTask($task->id);
    foreach ($taskPhotos as $photo) {
        if (!empty($photo->photo_url)) {
            $photoIndex++;
            $ext = pathinfo(parse_url($photo->photo_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $filesToZip["06_pruebas_servicios/foto_{$photoIndex}.{$ext}"] = $photo->photo_url;
        }
    }
}

if (empty($filesToZip)) {
    MessageUtil::setMessage("No files to download for this order", "Info", "info");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/closure-report?id=" . $orderId);
    return;
}

$zip = new ZipArchive();
$tmpZip = sys_get_temp_dir() . '/' . uniqid('closure_', true) . '.zip';

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    MessageUtil::setMessage("Error creating ZIP file", "Error", "error");
    LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/closure-report?id=" . $orderId);
    return;
}

$context = stream_context_create([
    'http' => ['timeout' => 15],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]);

foreach ($filesToZip as $zipPath => $url) {
    if (empty($url) || !preg_match('#^https?://#i', $url)) {
        continue;
    }
    $content = @file_get_contents($url, false, $context);
    if ($content !== false) {
        $zip->addFromString($zipPath, $content);
    }
}

$zip->close();

$downloadName = 'closure-report-order-' . $orderId . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache, must-revalidate');
readfile($tmpZip);
@unlink($tmpZip);
exit;
