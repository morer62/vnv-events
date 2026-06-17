<?php

use App\Repositories\OrdersFilesRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Services\LoginService;
use App\Services\UserWorkspaceContextService;
use App\Services\TranslationService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\NotificationService;

$router = new Router();

$router->get(function () {
    $repo = new OrdersFilesRepository();
    $docRepo = new DocumentsLogsRepository();
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
    $orderRepo = new OrdersRepository();
    $user = LoginService::getSession();
    $workspaceContext = (new UserWorkspaceContextService())->getClientContext($user);
    $selectedOwnerId = (int)($workspaceContext['selectedOwnerId'] ?? 0);
    $orderId = $_GET["id"];

    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    
    // Para nivel 5, solo verificar si es el cliente de la orden
    $hasAccess = $order
        && $order["id_client"] == $user->getId()
        && ($selectedOwnerId <= 0 || (int)$order["id_owner"] === $selectedOwnerId);

    if (!$hasAccess) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.order_not_found'));
        LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
    }

    // Obtener archivos normales de la orden
    $files = $repo->getAllBy(["id_order" => $orderId]);

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

    // Obtener contratos de aceptación desde OrdersAcceptanceContractsRepository
    $acceptanceContracts = $acceptanceRepo->getAllByOrder($orderId);

    // Convertir contratos de aceptación al formato de archivos
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
        "files" => $allFiles
    ]);
});

function uploadFile(): never {
    $orderId = $_GET["id"];
    $orderRepo = new OrdersRepository();
    $ordersFilesRepository = new OrdersFilesRepository();
    $user = LoginService::getSession();
    $workspaceContext = (new UserWorkspaceContextService())->getClientContext($user);
    $selectedOwnerId = (int)($workspaceContext['selectedOwnerId'] ?? 0);

    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    
    // Para nivel 5, solo verificar si es el cliente de la orden
    $hasAccess = $order
        && $order["id_client"] == $user->getId()
        && ($selectedOwnerId <= 0 || (int)$order["id_owner"] === $selectedOwnerId);

    if (!$hasAccess) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.order_not_found'));
        LocationUtils::redirectInternal("panel/planner-hub/orders/orders");
    }

    if (!FileUtils::hasFile($_FILES, "file")) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.file_not_provided'));
        LocationUtils::reload();
    }

    try {
        $location = FileUtils::saveFile($_FILES["file"], "order-files");
    } catch (Exception $e) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.file_upload_error'));
        LocationUtils::reload();
    }

    $ordersFilesRepository->add([
        "id_order" => $orderId,
        "title" => $_POST["title"] ?? "",
        "description" => $_POST["description"] ?? "",
        "file_path" => $location,
        ...LoginService::getOwnerAsArray()
    ]);

      // 🔔 Notificar al owner de la orden que el cliente subió un archivo
    NotificationService::sendToUsers(
        [$order["id_owner"]],
        TranslationService::trans('client_service_orders.notification_file_uploaded_title'),
        TranslationService::trans('client_service_orders.notification_file_uploaded_body', ['order_id' => 'VNV 341' . $orderId])
    );


    MessageUtil::setMessage(TranslationService::trans('client_service_orders.file_uploaded_success'));
    LocationUtils::reload();
}

function deleteFile(): never {
    $id = $_POST['id'];
    
    // Verificar si es un contrato (no se puede eliminar)
    if (strpos($id, 'contract_') === 0) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.contracts_protected_delete'));
        LocationUtils::reload();
    }
    
    // Verificar si es una aceptación (no se puede eliminar)
    if (strpos($id, 'acceptance_') === 0) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.acceptance_protected_delete'));
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
            MessageUtil::setMessage(TranslationService::trans('client_service_orders.file_not_found'));
            LocationUtils::reload();
        }

        // Si es order_acceptance_signed, no se puede eliminar
        if ($file->doc_type === 'order_acceptance_signed') {
            MessageUtil::setMessage(TranslationService::trans('client_service_orders.acceptance_protected_delete'));
            LocationUtils::reload();
        }

        $docRepo->delete([
            "id" => $receiptId
        ]);

        FileUtils::removeFile($file->file_path);
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.payment_receipt_deleted'));
        LocationUtils::reload();
    }
    
    // Si es un archivo normal (está en orders_files)
    $ordersFilesRepository = new OrdersFilesRepository();

    $file = $ordersFilesRepository->getOne([
        "id" => $id
    ]);

    if (is_null($file)) {
        MessageUtil::setMessage(TranslationService::trans('client_service_orders.file_not_found'));
        LocationUtils::reload();
    }

    $ordersFilesRepository->delete([
        "id" => $id
    ]);

    FileUtils::removeFile($file->file_path);
    MessageUtil::setMessage(TranslationService::trans('client_service_orders.file_deleted_success'));
    LocationUtils::reload();
}

$router->post(function () {
    if ($_POST["action"] == "delete") {
        deleteFile();
    }

    if ($_POST["action"] == "uploadFile") {
        uploadFile();
    }

    MessageUtil::setMessage(TranslationService::trans('client_service_orders.action_not_found'));
    LocationUtils::reload();
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
