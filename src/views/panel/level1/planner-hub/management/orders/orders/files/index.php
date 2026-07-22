<?php

use App\Repositories\OrdersFilesRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Services\LoginService;
use App\Services\TranslationService;
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
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
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
    $receipts_acceptance = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "order_acceptance_signed"
    ]);
    $receipts = array_merge(
        $receipts_legacy,
        $receipts_first,
        $receipts_second,
        $receipts_full,
        $receipts_sub_first,
        $receipts_sub_second,
        $receipts_sub_full,
        $receipts_tips,
        $receipts_acceptance
    );

    $acceptanceContracts = $acceptanceRepo->getAllByOrder($orderId);

    // Detectar idioma actual
    TranslationService::detectLocale();
    
    // Convertir contratos al formato de archivos para mostrar
    $contractFiles = array_map(function ($contract) use ($orderId) {
        return (object) [
            'id' => 'contract_' . $contract->id,
            'title' => TranslationService::trans('planner_hub.contract_signed', ['order_id' => $orderId]),
            'description' => TranslationService::trans('planner_hub.signed_contract_for_order'),
            'file_path' => $contract->file_path,
            'is_contract' => true,
            'contract_id' => $contract->id,
            'generated_at' => $contract->generated_at
        ];
    }, $contracts);

    // Convertir recibos al mismo formato
    $receiptFiles = array_map(function ($r) {
        $title = TranslationService::trans('planner_hub.payment_receipt');
        $description = TranslationService::trans('planner_hub.receipt_generated_after_payment');
        $type = trim($r->doc_type ?? '');
        if ($type === 'pay_first') { $title = TranslationService::trans('planner_hub.first_payment_receipt'); }
        if ($type === 'pay_second') { $title = TranslationService::trans('planner_hub.second_payment_receipt'); }
        if ($type === 'pay_full') { $title = TranslationService::trans('planner_hub.full_payment_receipt'); }
        if ($type === 'sub_pay_first') { $title = TranslationService::trans('planner_hub.suborder_first_payment_receipt'); }
        if ($type === 'sub_pay_second') { $title = TranslationService::trans('planner_hub.suborder_second_payment_receipt'); }
        if ($type === 'sub_pay_full') { $title = TranslationService::trans('planner_hub.suborder_full_payment_receipt'); }
        if ($type === 'tip_receipt') { 
            $title = TranslationService::trans('planner_hub.tip_receipt'); 
            $description = TranslationService::trans('planner_hub.gratuity_receipt');
        }
        if ($type === 'order_acceptance_signed') {
            $title = TranslationService::trans('planner_hub.order_acceptance_signed');
            $description = TranslationService::trans('planner_hub.client_signed_confirmation');
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

    $acceptanceFiles = array_map(function ($a) {
        return (object) [
            'id' => 'acceptance_' . $a->id,
            'title' => TranslationService::trans('planner_hub.order_acceptance_signed'),
            'description' => TranslationService::trans('planner_hub.client_signed_confirmation'),
            'file_path' => $a->file_path,
            'is_contract' => false,
            'contract_id' => null,
            'generated_at' => $a->generated_at
        ];
    }, $acceptanceContracts);

    // Combinar archivos normales, contratos, recibos y aceptaciones
    $allFiles = array_merge($files, $contractFiles, $receiptFiles, $acceptanceFiles);

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
        "files" => $allFiles,
        "order_id" => $orderId
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
    LocationUtils::redirectInternal(
        "panel/planner-hub/management/orders/orders/files/?id=" . urlencode((string)$orderId) . "&uploaded=1"
    );
}

function deleteFile(): never {
    $context = UserContext::get();

    $id = $_POST['id'];
    
    // Verificar si es un contrato (no se puede eliminar)
    if (strpos($id, 'contract_') === 0) {
        MessageUtil::setMessage("❌ Contracts cannot be deleted. They are automatically protected.");
        LocationUtils::reload();
    }
    
    // Verificar si es una aceptación (no se puede eliminar)
    if (strpos($id, 'acceptance_') === 0) {
        MessageUtil::setMessage("❌ Order acceptance contracts cannot be deleted. They are automatically protected.");
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
