<?php

use App\Repositories\OrdersFilesRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;
use App\Services\NotificationService;

$router = new Router();



$router->get(function () {
    $context = UserContext::get();

    $user = LoginService::getSession();

    $repo = new OrdersFilesRepository();
    $docRepo = new DocumentsLogsRepository();
    $orderRepo = new OrdersRepository();
    $orderId = $_GET["id"];

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $orderRepo->getOneByIdAndOwner($orderId, $institution->id_owner);
            } else {
                $order = null;
            }
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
        MessageUtil::setMessage("Order not found");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }

    // Obtener archivos normales de la orden
    $files = $repo->getAllBy([
        "id_order" => $orderId
    ]);

    // Obtener contratos firmados y recibos de pago
    $contracts = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "contract_signed"
    ]);
    // Recibos (compatibilidad hacia atrás y nuevos tipos específicos)
    $receipts_legacy = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "payment_receipt"
    ]);
    $receipts_first = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "pay_first"
    ]);
    $receipts_second = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "pay_second"
    ]);
    $receipts_full = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "pay_full"
    ]);
    $receipts_sub_first = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "sub_pay_first"
    ]);
    $receipts_sub_second = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "sub_pay_second"
    ]);
    $receipts_sub_full = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "sub_pay_full"
    ]);
    $receipts_tips = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "tip_receipt"
    ]);
    $receipts = array_merge(
        $receipts_legacy,
        $receipts_first,
        $receipts_second,
        $receipts_full,
        $receipts_sub_first,
        $receipts_sub_second,
        $receipts_sub_full,
        $receipts_tips
    );

    // Convertir contratos al formato de archivos para mostrar
    $contractFiles = array_map(function ($contract) {
        return (object) [
            'id' => 'contract_' . $contract->id,
            'title' => 'Contract Signed',
            'description' => 'Signed contract for this order',
            'file_path' => $contract->file_path,
            'is_contract' => true,
            'contract_id' => $contract->id,
            'generated_at' => $contract->generated_at
        ];
    }, $contracts);

    // Convertir recibos al mismo formato
    $receiptFiles = array_map(function ($r) {
        $title = 'Payment Receipt';
        $description = 'Receipt generated after payment';
        $type = trim($r->doc_type ?? '');
        if ($type === 'pay_first') { $title = 'First Payment Receipt'; }
        if ($type === 'pay_second') { $title = 'Second Payment Receipt'; }
        if ($type === 'pay_full') { $title = 'Full Payment Receipt'; }
        if ($type === 'sub_pay_first') { $title = 'Suborder First Payment Receipt'; }
        if ($type === 'sub_pay_second') { $title = 'Suborder Second Payment Receipt'; }
        if ($type === 'sub_pay_full') { $title = 'Suborder Full Payment Receipt'; }
        if ($type === 'tip_receipt') { 
            $title = '💝 Tip Receipt'; 
            $description = 'Gratuity receipt - Optional tip payment';
        }
        return (object) [
            'id' => 'receipt_' . $r->id,
            'title' => $title,
            'description' => $description,
            'file_path' => $r->file_path,
            'is_contract' => false,
            'contract_id' => null,
            'generated_at' => $r->generated_at
        ];
    }, $receipts);

    // Combinar archivos normales, contratos y recibos
    $allFiles = array_merge($files, $contractFiles, $receiptFiles);

    $allFiles = array_map(function ($file) {
        $mime = new Mimey\MimeTypes();
        $ext = explode(".", $file->file_path)[1] ?? '';

        if ($ext === "") {
            $file->is_image = false;
            return $file;
        }

        $mimeType = $mime->getMimeType($ext);
        $file->is_image = $mimeType ? str_contains($mimeType, "image") : false;
        return $file;
    }, $allFiles);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "files" => $allFiles
    ]);
});

function uploadFile(): never {
    $context = UserContext::get();

  
    $orderId = $_GET["id"];
    $orderRepo = new OrdersRepository();
    $ordersFilesRepository = new OrdersFilesRepository();

    $user = LoginService::getSession();
    $order = $orderRepo->getOne(["id" => $orderId]);

    if (
        !$order ||
        ($order->id_user != $user->getId() && $order->id_owner != $user->getOwner())
    ) {
        MessageUtil::setMessage("Order not found");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders");
    }


    if (!FileUtils::hasFile($_FILES, "file")) {
        MessageUtil::setMessage("File not provided");
        LocationUtils::reload();
    }

    $location = "";

    try {
        $location = FileUtils::saveFile($_FILES["file"], "order-files");
    } catch (Exception $e) {
        MessageUtil::setMessage("Error uploading file");
        LocationUtils::reload();
    }

    $ordersFilesRepository->add([
        "id_order" => $orderId,
        "title" => $_POST["title"] ?? "",
        "description" => $_POST["description"] ?? "",
        "file_path" => $location,
        ...LoginService::getUserIdAsArray()
    ]);

    // 🔔 Notificar al cliente y al owner sobre la carga de un archivo
    NotificationService::sendToUsers(
        [$order->id_client, $order->id_owner],
        '📁 New File Uploaded',
        'A new file was uploaded to Order VNV 341' . $orderId . '. Please log in to your account to view the document.'
    );


    MessageUtil::setMessage("File uploaded successfully");
    LocationUtils::reload();
}

function deleteFile(): never {
    $context = UserContext::get();

    $id = $_POST['id'];
    
    // Verificar si es un contrato (no se puede eliminar)
    if (strpos($id, 'contract_') === 0) {
        MessageUtil::setMessage("❌ Contracts cannot be deleted. They are automatically protected.");
        LocationUtils::reload();
    }
    
    // Verificar si es un recibo (está en document_logs)
    if (strpos($id, 'receipt_') === 0) {
        $docRepo = new DocumentsLogsRepository();
        $receiptId = str_replace('receipt_', '', $id);
        
        $file = $docRepo->getOne([
            "id" => $receiptId
        ]);

        if (is_null($file)) {
            MessageUtil::setMessage("File not found");
            LocationUtils::reload();
        }

        $docRepo->delete([
            "id" => $receiptId
        ]);

        FileUtils::removeFile($file->file_path);
        MessageUtil::setMessage("Payment receipt deleted successfully");
        LocationUtils::reload();
    }
    
    // Si es un archivo normal (está en orders_files)
    $ordersFilesRepository = new OrdersFilesRepository();

    $file = $ordersFilesRepository->getOne([
        "id" => $id
    ]);

    if (is_null($file)) {
        MessageUtil::setMessage("File not found");
        LocationUtils::reload();
    }

    $ordersFilesRepository->delete([
        "id" => $id
    ]);

    FileUtils::removeFile($file->file_path);
    MessageUtil::setMessage("File deleted successfully");
    LocationUtils::reload();
}

$router->post(function () {
    if ($_POST["action"] == "delete") {
        deleteFile();
    }

    if ($_POST["action"] == "uploadFile") {
        uploadFile();
    }

    MessageUtil::setMessage("Action not found");
    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
