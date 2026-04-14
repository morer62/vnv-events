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
use App\Repositories\SquareAccountsRepository;
use App\Repositories\NotificationsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
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

    if ($hashCheck !== $decoded["hash"] || time() > $decoded["exp"]) {
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

    $squareRepo = new SquareAccountsRepository();
    $squareAccount = $squareRepo->getByUser($parentOrder->id_owner);
    $isSquareReady = $squareAccount && $squareAccount->is_verified == 1;

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

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "docs" => $docs,
        "token" => $_GET["token"],
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
        "isSquareReady" => $isSquareReady,
        "square_application_id" => $_ENV["SQUARE_APPLICATION_ID"] ?? "",
        "square_location_id" => $_ENV["SQUARE_LOCATION_ID"] ?? "",
        "square_environment" => $_ENV["SQUARE_ENVIRONMENT"] ?? "sandbox",
        "current_status" => $parentOrder->status_workflow,
        "processingModal" => ProcessingModal::render("orderAccessProcessingModal", [
            "title" => "Processing request...",
            "message" => "We are completing your action. Please wait a few seconds."
        ]),
        "processingModalScript" => ProcessingModal::script("orderAccessProcessingModal")
    ]);
});

// POST
$router->post(function () {
    $token = $_POST["token"] ?? null;
    if (!$token)
        return Response::createResponse("Token missing");

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

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
        return Response::createResponse("Suborder not found");

    $parentOrder = $orderRepo->getByIdWithoutOwnershipCheck($suborder->id_order);
    if ($parentOrder) {
        $parentOrder = (object)$parentOrder;
    }
    if (!$parentOrder)
        return Response::createResponse("Parent order not found");

    $filename = null;
    $userLocalTimestamp = $_POST["user_local_timestamp"] ?? null;
    
    if (!empty($_FILES["signature_image"]["tmp_name"])) {
        $filename = FileUtils::saveFile($_FILES["signature_image"], "files/contracts/");
        // También generar PDF con la hora del usuario cuando se sube imagen
        $filename = ContractPdfGenerator::generateAndSave($parentOrder->id, $userLocalTimestamp);
    } elseif (!empty($_POST["typed_initials"])) {
        $filename = ContractPdfGenerator::generateAndSave($parentOrder->id, $userLocalTimestamp);
    }

    if (!$filename)
        return Response::createResponse("No signature provided");

    // Usar hora local del usuario si está disponible, sino usar hora del servidor
    $userLocalTimestamp = $_POST["user_local_timestamp"] ?? null;
    $generatedAt = $userLocalTimestamp ? $userLocalTimestamp : date("Y-m-d H:i:s");

    // Guardar documento de contrato firmado
    $docResult = $docRepo->add([
        "id_order" => $parentOrder->id,
        "id_user" => $userId,
        "doc_type" => "contract_signed",
        "file_path" => $filename,
        "hash" => hash_file("sha256", $filename),
        "ip" => $_SERVER["REMOTE_ADDR"],
        "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? '',
        "extra" => json_encode([
            "method" => $_FILES["signature_image"] ? "upload" : "initials",
            "suborder_id" => $suborderId
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
            "note" => "Contract signed by client for suborder #" . $suborderId,
            "created_by" => $userId,
        ]);
    }

    $notificationsRepo->add([
        "id_user" => $parentOrder->id_owner,
        "mensaje" => "✍️ Contract Signed - The client has successfully signed the contract for suborder #" . $suborderId . " of order #VNV341" . $parentOrder->id,
        "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/management/orders/orders/suborders?id=" . $parentOrder->id,
        "leido" => 0
    ]);

    // Redirect con timestamp para evitar caché
    $redirectUrl = "order-access/suborder?token=" . urlencode($token) . "&t=" . time();
    LocationUtils::redirectInternal($redirectUrl);
});

$router->run();
