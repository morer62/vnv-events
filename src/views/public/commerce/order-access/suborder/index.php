<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Response;
use App\Utils\FileUtils;
use App\Services\ContractPdfGenerator;
use App\Services\OrderCalculatorService;
use App\Services\NotificationService;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\NotificationsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Services\TranslationService;
use App\Utils\ProcessingModal;

$router = new \App\Utils\Router();

// GET
$router->get(function () {
    $token = $_GET["token"] ?? null;
    if (!$token)
        LocationUtils::redirectInternal("/404");

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

    if (!$decoded || !isset($decoded["suborder_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        LocationUtils::redirectInternal("/404");
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "suborder_id" => $decoded["suborder_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        LocationUtils::redirectInternal("/404");
    }

    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();
    $docRepo = new DocumentsLogsRepository();
    $contractRepo = new OrdersContractRepository();
    $paymentRepo = new OrdersPaymentsRepository();

    $suborder = $suborderRepo->getByIdWithoutOwnershipCheck($decoded["suborder_id"]);
    if (!$suborder)
        LocationUtils::redirectInternal("/404");

    // Obtener la orden padre
    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    
    if (!$parentOrder)
        LocationUtils::redirectInternal("/404");

    $paymentProvidersRepo = new PaymentProvidersRepository();
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($parentOrder);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
    $isPaymentReady = $activeProvider && $activeProvider->is_verified && in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true);
    $isSquareReady = $isPaymentReady && $activeProvider->provider_type === 'square';

    // Verificar si ya se firmó el contrato de la orden padre
    $docs = $docRepo->getAllByOrder($parentOrder->id);
    $hasSigned = false;
    foreach ($docs as $doc) {
        if ($doc->doc_type === 'contract_signed') {
            $hasSigned = true;
            break;
        }
    }

    // Verificar pagos de la suborden
    $payments = $paymentRepo->getAllBy(["id_suborder" => $suborder->id]);
    $paymentStatus = 'pending_first';
    if (count($payments) > 0) {
        if ($suborder->payment_split_type == 2) {
            $paymentStatus = count($payments) === 1 ? 'pending_second' : 'complete';
        } elseif ($suborder->payment_split_type == 1) {
            $paymentStatus = 'complete';
        }
    }

    // Obtener servicios de la suborden
    $suborderServices = $suborderServicesRepo->getServicesWithDetails($suborder->id);
    $servicesFormatted = [];
    $subtotalCalculated = 0;
    
    foreach ($suborderServices as $service) {
        $subtotalCalculated += $service->quantity * $service->actual_price;
        
        $servicesFormatted[] = [
            "name" => $service->service_name,
            "qty" => $service->quantity,
            "unit_price" => $service->actual_price,
            "note" => $service->service_description,
            "subtotal" => $service->quantity * $service->actual_price,
            "is_variable" => $service->is_variable ?? 'NO'
        ];
    }
    
    // Calcular descuento y montos finales (descuento antes de impuestos)
    $discountType = $suborder->discount_type ?? 'amount';
    $discountValue = (float)($suborder->discount_value ?? 0);
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $discount = $discountValue;
    $discount = max(0, $discount);

    $base = max($subtotalCalculated - $discount, 0);
    $taxRate = (float)($suborder->tax_percertance ?? 0);
    $tax = $base * ($taxRate / 100);
    $total = $base + $tax;

    // Restar abonos de suborden
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
    }

    $total = max($total - $sumAdvances, 0);
    
    // Check if suborder is fully paid through advances (total remaining is 0 or negative)
    if ($total <= 0) {
        $paymentStatus = 'complete';
    }
    
    $firstPercent = $suborder->payment_split_percent_1 ?? 50;
    $secondPercent = $suborder->payment_split_percent_2 ?? 50;

    $firstPayment = round($total * $firstPercent / 100, 2);
    
    // For second installment, calculate remaining balance after advances and previous payments
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_suborder = :id AND is_suborder = 1");
        $db->bind(":id", $suborder->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) {
        $sumPaid = 0;
    }
    
    $remainingForSecond = max($total - $sumPaid, 0);
    $secondPayment = round($remainingForSecond, 2);
    $secondPaymentOriginal = round($total * $secondPercent / 100, 2);

    // Generar token para la orden principal (enlace de regreso)
    $orderPayload = [
        "order_id" => $parentOrder->id,
        "user_id" => $decoded["user_id"],
        "exp" => time() + 60 * 60 * 24, // 24h
    ];
    $orderPayload["hash"] = hash_hmac("sha256", json_encode([
        "order_id" => $orderPayload["order_id"],
        "user_id" => $orderPayload["user_id"],
        "exp" => $orderPayload["exp"]
    ]), $secret);
    $orderToken = base64_encode(json_encode($orderPayload));
    $baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "docs" => $docs,
        "token" => $_GET["token"],
        "base_url" => $baseUrl,
        "parent_order_token" => $orderToken,
        "hasSigned" => $hasSigned,
        "paymentStatus" => $paymentStatus,
        "payment_type" => $suborder->payment_split_type == 2 ? 'split' : 'one',

        "first_payment_amount" => $firstPayment,
        "second_payment_amount" => $secondPayment,
        "second_payment_original" => $secondPaymentOriginal,
        "total" => $total,
        "subtotal" => $subtotalCalculated,
        "discount" => $discount,
        "discount_type" => $discountType,
        "discount_value" => $discountValue,
        "tax" => $tax,
        "advances_total" => $sumAdvances,

        "services" => $servicesFormatted,
        "currency_code" => strtoupper($parentOrder->currency ?? ($activeProvider ? $activeProvider->currency : null) ?? 'USD'),
        "isSquareReady" => $isSquareReady,
        "isPaymentReady" => $isPaymentReady,
        "square_application_id" => ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->public_key ?? '') : '',
        "square_location_id" => ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->location_id ?? '') : '',
        "square_environment" => ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox',
        "current_status" => $parentOrder->status_workflow,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => TranslationService::trans('planner_hub.processing_request'),
            "message" => TranslationService::trans('planner_hub.completing_action')
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

// POST
$router->post(function () {
    $token = $_POST["token"] ?? null;
    if (!$token)
        return Response::createResponse(TranslationService::trans('planner_hub.token_missing'));

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

    if (!$decoded || !isset($decoded["suborder_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        return Response::createResponse(TranslationService::trans('planner_hub.invalid_token'));
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "suborder_id" => $decoded["suborder_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        return Response::createResponse(TranslationService::trans('planner_hub.invalid_token'));
    }

    $suborderId = intval($decoded["suborder_id"]);
    $userId = intval($decoded["user_id"]);

    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();
    $docRepo = new DocumentsLogsRepository();
    $notificationsRepo = new NotificationsRepository();

    $suborder = $suborderRepo->getByIdWithoutOwnershipCheck($suborderId);
    if ($suborder) {
        $suborder = (object)$suborder;
    }
    if (!$suborder)
        return Response::createResponse(TranslationService::trans('planner_hub.suborder_not_found'));

    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    if (!$parentOrder)
        return Response::createResponse(TranslationService::trans('planner_hub.parent_order_not_found'));

    if ($docRepo->getByType((int)$parentOrder->id, 'contract_signed')) {
        return Response::createResponse(TranslationService::trans('ui.contract_already_signed'));
    }

    $userLocalTimestamp = $_POST["user_local_timestamp"] ?? null;
    if (empty($_POST["e_sign_consent"])) {
        return Response::createResponse(TranslationService::trans('ui.e_sign_consent_required'));
    }

    if (!empty($_FILES["signature_image"]["tmp_name"])) {
        FileUtils::saveFile($_FILES["signature_image"], "files/contracts/");
    }
    if (empty($_FILES["signature_image"]["tmp_name"]) && empty(trim($_POST["typed_initials"] ?? '')))
        return Response::createResponse(TranslationService::trans('planner_hub.no_signature_provided'));

    $result = ContractPdfGenerator::generateAndSave($parentOrder->id, $userLocalTimestamp);
    $filename = is_array($result) ? ($result['file_path'] ?? '') : (string)$result;
    $contentHash = is_array($result) ? ($result['hash'] ?? hash('sha256', $filename)) : hash('sha256', $filename);

    if (!$filename)
        return Response::createResponse(TranslationService::trans('planner_hub.no_signature_provided'));

    $generatedAt = $userLocalTimestamp ?: date("Y-m-d H:i:s");
    $signatureMethod = !empty($_FILES["signature_image"]["tmp_name"]) ? 'upload' : 'initials';

    $docRepo->add([
        "id_order" => $parentOrder->id,
        "id_user" => $userId,
        "doc_type" => "contract_signed",
        "file_path" => $filename,
        "hash" => $contentHash,
        "ip" => $_SERVER["REMOTE_ADDR"] ?? '',
        "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? '',
        "extra" => json_encode([
            "method" => $signatureMethod,
            "e_sign_consent" => true,
            "suborder_id" => $suborderId,
            "document_id" => "VNV-341" . $parentOrder->id,
        ]),
        "generated_at" => $generatedAt
    ]);

    // Actualizar status de la orden padre si es necesario
    if ($parentOrder->status_workflow === 'INVOICE_DRAFT') {
        $orderRepo->update(
            ["status_workflow" => "INVOICE_READY"], 
            ["id" => $parentOrder->id]             
        );

        $statusHistoryRepo = new OrdersStatusHistoryRepository();
        $statusHistoryRepo->add([
            "id_order" => $parentOrder->id,
            "status" => "INVOICE_READY",
            "action_type" => "contract_signed",
            "file_path" => $filename,
            "note" => TranslationService::trans('planner_hub.contract_signed_for_suborder', ['suborder_id' => $suborderId]),
            "created_by" => $userId,
        ]);
    }

    // Enlace público para la suborden firmada (para owner y cliente)
    $publicSuborderUrl = ($_ENV["APP_URL"] ?? "vnv-venue") . "/order-access/suborder?token=" . $token;

    $notificationsRepo->add([
        "id_user" => $parentOrder->id_owner,
        "mensaje" => "✍️ " . TranslationService::trans('planner_hub.contract_signed_suborder_notification', [
            'suborder_id' => $suborderId,
            'order_id' => $parentOrder->id
        ]),
        "link" => $publicSuborderUrl,
        "leido" => 0
    ]);

    // Redirect con timestamp para evitar caché
    $redirectUrl = "order-access/suborder?token=" . urlencode($token) . "&t=" . time();
    LocationUtils::redirectInternal($redirectUrl);
});

$router->run();
