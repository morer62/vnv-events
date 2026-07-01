<?php

use App\Repositories\OrdersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Repositories\OrdersAcceptanceContractTemplateRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\Response;
use App\Utils\FileUtils;
use App\Services\ContractPdfGenerator;
use App\Services\OrderCalculatorService;
use App\Services\NotificationService;
use App\Services\EmailService;
use App\Services\DocuSignService;
use App\Services\LoginService;
use App\Services\OrderAccessSavedPaymentMethodService;
use App\Repositories\SquareAccountsRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\NotificationsRepository;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Services\TranslationService;
use App\Utils\ProcessingModal;
use App\Repositories\TipsRepository;


$router = new \App\Utils\Router();

// GET
$router->get(function () {
    $token = $_GET["token"] ?? null;
    if (!$token)
        LocationUtils::redirectInternal("/404");

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

    if (!$decoded || !isset($decoded["order_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        LocationUtils::redirectInternal("/404");
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "order_id" => $decoded["order_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        LocationUtils::redirectInternal("/404");
    }

    if (isset($_GET['docusign_return']) && $_GET['docusign_return'] == '1' && isset($_GET['envelope_id'])) {
        $envelopeId = trim(urldecode($_GET['envelope_id'] ?? ''));
        
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $envelopeId)) {
            $orderRepo = new OrdersRepository();
            $docRepo = new DocumentsLogsRepository();
            $notificationsRepo = new NotificationsRepository();
            
            $order = $orderRepo->getByIdWithoutOwnershipCheck($decoded["order_id"]);
            if ($order) {
                $order = (object)$order;
            }
            
            if ($order && $order->status_workflow === 'INVOICE_DRAFT') {
                $docuSignService = new DocuSignService();
                
                if ($docuSignService->isConfigured()) {
                    $documentUrl = ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/api/docusign/document?envelope_id=" . urlencode($envelopeId);
                    
                    $docRepo->add([
                        "id_order" => $order->id,
                        "id_user" => $decoded["user_id"],
                        "doc_type" => "contract_signed",
                        "file_path" => $documentUrl,
                        "hash" => hash('sha256', $envelopeId),
                        "ip" => $_SERVER["REMOTE_ADDR"] ?? '',
                        "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? '',
                        "extra" => json_encode(["envelope_id" => $envelopeId, "method" => "docusign"]),
                        "generated_at" => date("Y-m-d H:i:s")
                    ]);
                    
                    $orderRepo->update(
                        ["status_workflow" => "INVOICE_READY"],
                        ["id" => $order->id]
                    );
                    
                    $statusHistoryRepo = new OrdersStatusHistoryRepository();
                    $statusHistoryRepo->add([
                        "id_order" => $order->id,
                        "status" => "INVOICE_READY",
                        "action_type" => "contract_signed",
                        "file_path" => $documentUrl,
                        "note" => "Contract signed via DocuSign",
                        "created_by" => $decoded["user_id"],
                    ]);
                    
                    $notificationsRepo->add([
                        "id_user" => $order->id_owner,
                        "mensaje" => "✍️ Contract Signed - The client has successfully signed the contract for order #VNV341" . $order->id,
                        "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/panel/planner-hub/management/orders/orders",
                        "leido" => 0
                    ]);
                    
                    $notificationsRepo->add([
                        "id_user" => $decoded["user_id"],
                        "mensaje" => "✅ Contract Signed Successfully - Your contract for order #VNV341" . $order->id . " has been signed and is now ready for payment.",
                        "link" => ($_ENV["APP_URL"] ?? "vnv-venue") . "/order-access?token=" . urlencode($token),
                        "leido" => 0
                    ]);
                    
                    try {
                        $emailService = new EmailService();
                        $userRepo = new \App\Repositories\UserRepository();
                        $client = $userRepo->getOne(["id" => $decoded["user_id"]]);
                        
                        if ($client && $client->email) {
                            $subject = "VNV-Events - ✅ Contract Signed Successfully - Order #VNV341" . $order->id;
                            $templateData = [
                                'orderId' => $order->id,
                                'eventDate' => date("F j, Y", strtotime($order->event_date)),
                                'eventTime' => date("g:i A", strtotime($order->start_time)) . ' to ' . date("g:i A", strtotime($order->end_time)),
                                'location' => $order->address,
                                'orderUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/order-access?token=" . urlencode($token)
                            ];
                            
                            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/contract_signed.php");
                            $emailService->sendTemplateEmail(
                                $client->email,
                                $subject,
                                $templatePath,
                                $templateData
                            );
                        }
                    } catch (\Exception $e) {
                        // Silently fail email
                    }
                }
            }
            
            $redirectUrl = "order-access?token=" . urlencode($token) . "&signed=1&t=" . time();
            LocationUtils::redirectInternal($redirectUrl);
            return;
        }
    }

    $orderRepo = new OrdersRepository();
    $docRepo = new DocumentsLogsRepository();
    $contractRepo = new OrdersContractRepository();
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
    $acceptanceTemplateRepo = new OrdersAcceptanceContractTemplateRepository();
    $paymentRepo = new OrdersPaymentsRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();

    $order = $orderRepo->getByIdWithoutOwnershipCheck($decoded["order_id"]);
    
    if ($order) {
        $order = (object)$order;
    }
    
    if (!$order)
        LocationUtils::redirectInternal("/404");

    $eventDateTs = strtotime((string)($order->event_date ?? ''));
    $todayTs = strtotime(date('Y-m-d'));
    $isEventPast = ($eventDateTs !== false) && ($eventDateTs < $todayTs);

    $userRepo = new \App\Repositories\UserRepository();
    $tokenUser = $userRepo->getOneWithoutOwnership(["id" => (int) ($decoded["user_id"] ?? 0)]);
    $sessionUser = LoginService::getSession();

    $canViewAllOrders = false;
    $allOrdersUrl = null;

    if ($tokenUser) {
        $tokenLevel = (int) ($tokenUser->level ?? 0);
        $tokenOwnerId = in_array($tokenLevel, [1, 2, 3], true)
            ? (int) ($tokenUser->id ?? 0)
            : (int) ($tokenUser->id_owner ?? 0);
        if (in_array($tokenLevel, [1, 2, 3, 4], true) && $tokenOwnerId === (int) $order->id_owner) {
            $canViewAllOrders = true;
        }
    }

    if (!$canViewAllOrders && $sessionUser) {
        $sessionLevel = (int) $sessionUser->getLevel();
        $sessionOwnerId = in_array($sessionLevel, [1, 2, 3], true)
            ? (int) $sessionUser->getId()
            : (int) $sessionUser->getOwner();
        if (in_array($sessionLevel, [1, 2, 3, 4], true) && $sessionOwnerId === (int) $order->id_owner) {
            $canViewAllOrders = true;
        }
    }

    if ($canViewAllOrders) {
        $allOrdersUrl = (($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/panel/planner-hub/management/orders/orders");
    }

    $paymentProvidersRepo = new PaymentProvidersRepository();
    $paymentOwnerId = $paymentProvidersRepo->getPaymentOwnerIdForOrder($order);
    $activeProvider = $paymentProvidersRepo->getActiveProviderForOwner($paymentOwnerId);
    $isPaymentReady = $activeProvider && $activeProvider->is_verified && in_array($activeProvider->provider_type, ['stripe', 'square', 'paypal'], true);
    $isSquareReady = $isPaymentReady && $activeProvider->provider_type === 'square';
    $savedPaymentContext = [
        'can_use_saved_payment_methods' => false,
        'saved_payment_methods' => [],
        'supports_future_payment_methods' => false,
        'payment_consent_text' => '',
        'payment_consent_version' => '',
    ];
    if ($isPaymentReady && $activeProvider) {
        $savedPaymentContext = (new OrderAccessSavedPaymentMethodService())->viewDataForOrder(
            $order,
            (int)$paymentOwnerId,
            strtolower((string)$activeProvider->provider_type)
        );
    }

    $docuSignService = new DocuSignService();
    $isDocuSignReady = $docuSignService->isConfigured();

    $docs = $docRepo->getAllByOrder($order->id);
    // Solo obtener pagos de la orden principal (no de subórdenes)
    $allPayments = $paymentRepo->getAllBy(["id_order" => $order->id]);
    $payments = [];
    foreach ($allPayments as $p) {
        // Solo incluir pagos que no son de subórdenes
        // Verificar tanto id_suborder como is_suborder para mayor robustez
        $idSuborder = is_object($p) ? ($p->id_suborder ?? null) : (isset($p['id_suborder']) ? $p['id_suborder'] : null);
        $isSuborder = is_object($p) ? ($p->is_suborder ?? null) : (isset($p['is_suborder']) ? $p['is_suborder'] : null);
        
        if (($idSuborder === null || $idSuborder == 0) && ($isSuborder === null || $isSuborder == 0)) {
            $payments[] = $p;
        }
    }

    $hasSigned = in_array((string)($order->status_workflow ?? ''), [
        'INVOICE_READY',
        'INVOICE_PARTIAL',
        'INVOICE_PAID',
    ], true);
    $hasSignedAcceptance = false;
    foreach ($docs as $doc) {
        if (strtolower(trim((string)($doc->doc_type ?? ''))) === 'contract_signed') {
            $hasSigned = true;
        }
        if (strtolower(trim((string)($doc->doc_type ?? ''))) === 'order_acceptance_signed') {
            $hasSignedAcceptance = true;
        }
        if ($hasSigned && $hasSignedAcceptance) break;
    }
    
    if (!$hasSignedAcceptance) {
        $acceptanceContract = $acceptanceRepo->getByOrder($order->id);
        if ($acceptanceContract) {
            $hasSignedAcceptance = true;
        }
    }

    $acceptanceTemplate = $acceptanceTemplateRepo->getOrCreateByOwner($order->id_owner);
    $acceptanceText = $acceptanceTemplate->content ?? TranslationService::trans('planner_hub.accept_order_confirmation');
    $acceptanceText = str_replace('#ORDER_ID#', 'VNV-341' . $order->id, $acceptanceText);

    // Obtener contrato
    $contract = $order->id_contract ? $contractRepo->getByIdWithoutOwnershipCheck($order->id_contract) : null;

    // Obtener información del cliente
    // Usar getOneWithoutOwnership para asegurar que obtenemos todos los campos incluyendo ui_language
    $client = $userRepo->getOneWithoutOwnership(["id" => $order->id_client]);
    
    // Detectar y establecer el idioma de la interfaz del cliente
    // Si el cliente tiene ui_language configurado, usarlo; si no, detectar del navegador
    if ($client && isset($client->ui_language) && !empty($client->ui_language)) {
        // Establecer el locale del cliente desde la base de datos
        TranslationService::setLocale($client->ui_language);
    } else {
        // Si no hay idioma configurado, detectar del navegador o usar el de la sesión
        // detectLocale() ya maneja la sesión, cookies y navegador
        TranslationService::detectLocale();
    }
    
    // Asegurar que el locale esté establecido antes de renderizar
    // Esto es importante porque TemplateResponse::render() también llama a detectLocale()
    // pero respetará el locale ya establecido en $_SESSION

    // Servicios asignados con nombre y subtotal
    $assigned = $assignedRepo->getAllWithoutOwner(["id_order" => $order->id]);
    $servicesFormatted = [];
    $subtotalCalculated = 0;
    
    foreach ($assigned as $a) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($a->id_service);
        
        // Usar el precio histórico almacenado (unit_price) si existe, sino usar el precio actual del servicio
        if (isset($a->unit_price) && $a->unit_price > 0) {
            $unitPrice = $a->unit_price;
        } else {
            // Fallback para órdenes antiguas que no tienen unit_price
            $unitPrice = ($a->is_variable === 'YES' && $a->variable_price !== null) 
                ? $a->variable_price 
                : ($service->price ?? 0);
        }
        
        $subtotalCalculated += $a->quantity * $unitPrice;
        
        // Usar la descripción histórica guardada si existe, sino usar la del servicio actual
        $description = null;
        if ($a->is_variable !== 'YES') {
            if (isset($a->description) && $a->description) {
                $description = $a->description; // Descripción histórica
            } else {
                $description = $service->description ?? null; // Fallback para órdenes antiguas
            }
        }
        
        $servicesFormatted[] = [
            "name" => $service->name ?? ('Service #' . ($a->id_service ?? '')),
            "qty" => $a->quantity,
            "unit_price" => $unitPrice,
            "note" => $description,
            "subtotal" => $a->quantity * $unitPrice,
            "description_url" => $service->description_url ?? null,
            "is_variable" => $a->is_variable ?? 'NO'
        ];
    }

    // Calculate amounts using real subtotal with variable prices
    $discountType = $order->discount_type ?? 'amount';
    $discountValue = $order->discount_value ?? 0;
    
    // El discount_value ya es el monto real calculado, no necesitamos recalcular
    $descuento = $discountValue;
    
    $base = max($subtotalCalculated - $descuento, 0);
    $taxRate = $order->tax_percentage ?? 0;
    $tax = $base * ($taxRate / 100);
    
    $tipAmount = 0;
    $tipPercentage = 0;
    if (!empty($order->id_tip)) {
        $tipsRepo = new TipsRepository();
        $tip = $tipsRepo->getOne(["id" => $order->id_tip]);
        if ($tip && $tip->is_active == 1) {
            $tipAmount = $base * ($tip->percentage / 100);
            $tipPercentage = $tip->percentage;
        }
    }
    
    $total = $base + $tax + $tipAmount;

    // Subtract registered advances
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_advanced FROM orders_advances WHERE id_order = :id AND is_suborder = 0");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumAdvances = (float)($row->total_advanced ?? 0);
    } catch (\Throwable $e) {
        $sumAdvances = 0;
    }
    $totalBeforeAdvances = $total;
    $total = max($total - $sumAdvances, 0);
    
    // Check if order is fully paid through advances (total remaining is 0 or negative)
    $paymentStatus = 'pending_first';
    if ($total <= 0) {
        $paymentStatus = 'complete';
    }
    
    $firstPercent = $order->payment_split_percent_1 ?? 50;
    $secondPercent = $order->payment_split_percent_2 ?? 50;

    // Pago 1 según porcentaje configurado
    $firstPayment = round($total * $firstPercent / 100, 2);

    // Pago 2 según porcentaje configurado
    $secondPayment = round($total * $secondPercent / 100, 2);
    $secondPaymentOriginal = $secondPayment;

    // Si ya hay pagos previos (abonos), ajustar el segundo pago al saldo real
    // Calcular el monto total pagado (usando la misma consulta que se usa más abajo)
    try {
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM orders_payments WHERE id_order = :id AND (id_suborder IS NULL OR id_suborder = 0)");
        $db->bind(":id", $order->id);
        $db->execute();
        $row = $db->fetchAll()[0] ?? null;
        $sumPaid = (float)($row->total_paid ?? 0);
    } catch (\Throwable $e) {
        $sumPaid = 0;
    }

    if ($sumPaid > 0) {
        $remaining = max($total - $sumPaid, 0);
        $secondPayment = round($remaining, 2);
    }
    
    // Verificar pagos (solo de la orden principal) - Mantener lógica simple como antes
    // Pero verificando montos en lugar de solo contar pagos
    if ($paymentStatus !== 'complete') {
        // Primero verificar el estado de la orden como fuente de verdad
        if ($order->status_workflow === 'INVOICE_PAID') {
            $paymentStatus = 'complete';
        } elseif ($order->status_workflow === 'INVOICE_PARTIAL') {
            // Si está marcada como parcial, verificar si realmente falta el segundo pago
            if ($order->payment_split_type == 2) {
                if ($sumPaid >= ($total - 0.01)) {
                    // Si el monto pagado ya cubre el total, marcar como completo
                    $paymentStatus = 'complete';
                } else {
                    // Si está parcial pero falta el segundo pago
                    $paymentStatus = 'pending_second';
                }
            }
        } elseif ($sumPaid > 0) {
            // Verificar basado en montos pagados (similar a la lógica anterior pero verificando montos)
            if ($order->payment_split_type == 2) {
                // Pago split: verificar si se pagó el primer pago y si falta el segundo
                // Usar tolerancia de 0.01 para manejar problemas de redondeo
                if ($sumPaid >= ($total - 0.01)) {
                    $paymentStatus = 'complete';
                } elseif ($sumPaid >= ($firstPayment - 0.01)) {
                    $paymentStatus = 'pending_second';
                }
                // Si no se ha pagado el primer pago, queda como 'pending_first'
            } elseif ($order->payment_split_type == 1) {
                // Pago único: verificar si se pagó el total completo
                if ($sumPaid >= ($total - 0.01)) {
                    $paymentStatus = 'complete';
                }
                // Si no se ha pagado el total, queda como 'pending_first'
            }
        }
    }


    // Construir lista simple de subórdenes con enlaces (solo las no archivadas)
    $subordersRepo = new \App\Repositories\OrdersSuborderRepository();
    $suborders = $subordersRepo->getAllBy([
        "id_order" => $order->id,
        "is_archived" => 0
    ]);

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $subordersList = [];
    foreach ($suborders as $s) {
        $payload = [
            "suborder_id" => $s->id,
            "user_id" => $decoded["user_id"],
            "exp" => time() + 60 * 60 * 24,
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode([
            "suborder_id" => $payload["suborder_id"],
            "user_id" => $payload["user_id"],
            "exp" => $payload["exp"]
        ]), $secret);
        $tokenSub = base64_encode(json_encode($payload));

        $subordersList[] = [
            "id" => $s->id,
            "status_workflow" => $s->status_workflow,
            "payment_split_type" => $s->payment_split_type,
            "link" => (($_ENV["APP_URL"] ?? "") . "/order-access/suborder?token=" . urlencode($tokenSub))
        ];
    }

    $payment_type = $order->payment_split_type == 2 ? 'split' : 'one';
    
    $tipSuccess = isset($_GET['tip_success']) && $_GET['tip_success'] == '1';
    $lastTipPayment = null;
    if ($tipSuccess) {
        try {
            $db = new \App\Repositories\Connection();
            $db->query("SELECT * FROM orders_payments WHERE id_order = :id AND payment_concept = 'Gratuity/Tip' ORDER BY paid_at DESC LIMIT 1");
            $db->bind(":id", $order->id);
            $db->execute();
            $lastTipPayment = $db->fetchAll()[0] ?? null;
        } catch (\Throwable $e) {
            $lastTipPayment = null;
        }
    }

    $paymentRequest = [
        "enabled" => false,
        "type" => null,
        "amount_cents" => null,
        "label" => null,
    ];

    if ($hasSigned && $isPaymentReady && $paymentStatus !== 'complete' && !$isEventPast) {
        if ($payment_type === 'one' && $paymentStatus === 'pending_first' && $total > 0) {
            $paymentRequest = [
                "enabled" => true,
                "type" => 'full',
                "amount_cents" => (int) round($total * 100),
                "label" => TranslationService::trans('planner_hub.order_full_payment_label', ['order_id' => $order->id]),
            ];
        } elseif ($payment_type === 'split' && $paymentStatus === 'pending_first' && $firstPayment > 0) {
            $paymentRequest = [
                "enabled" => true,
                "type" => 'first',
                "amount_cents" => (int) round($firstPayment * 100),
                "label" => TranslationService::trans('planner_hub.order_first_installment', ['order_id' => $order->id]),
            ];
        } elseif ($payment_type === 'split' && $paymentStatus === 'pending_second' && $secondPayment > 0) {
            $paymentRequest = [
                "enabled" => true,
                "type" => 'second',
                "amount_cents" => (int) round($secondPayment * 100),
                "label" => TranslationService::trans('planner_hub.order_remaining_balance', ['order_id' => $order->id]),
            ];
        }
    }

    $currencyCode = strtoupper($order->currency ?? ($activeProvider ? $activeProvider->currency : null) ?? 'USD');
    $paymentProviderName = '';
    if ($activeProvider && $isPaymentReady) {
        $paymentProviderName = ucfirst($activeProvider->provider_type);
    }

    $baseUrl = $_ENV["APP_URL"] ?? 'http://localhost/vnv-venue';

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "order" => $order,
        "client" => $client,
        "docs" => $docs,
        "token" => $_GET["token"],
        "base_url" => $baseUrl,
        "can_view_all_orders" => $canViewAllOrders,
        "all_orders_url" => $allOrdersUrl,
        "hasSigned" => $hasSigned,
        "hasSignedAcceptance" => $hasSignedAcceptance,
        "paymentStatus" => $paymentStatus,
        "payment_type" => $order->payment_split_type == 2 ? 'split' : 'one',

        "contract_summary" => $contract ? $contract->content : TranslationService::trans('planner_hub.no_contract_assigned'),
        "acceptance_text" => $acceptanceText,
        "first_payment_amount" => $firstPayment,
        "second_payment_amount" => $secondPayment,
        "second_payment_original" => $secondPaymentOriginal,
        "total" => $total,
        "advances_total" => $sumAdvances,
        "total_before_advances" => $totalBeforeAdvances,
        "subtotal" => $subtotalCalculated,
        "tax" => $tax,
        "tip" => $tipAmount,
        "tip_percentage" => $tipPercentage,

        "services" => $servicesFormatted,
        "suborders" => $subordersList,
        "isSquareReady" => $isSquareReady,
        "isDocuSignReady" => $isDocuSignReady,
        "isPaymentReady" => $isPaymentReady,
        "activeProvider" => $activeProvider ? (object)[
            "provider_type" => $activeProvider->provider_type,
            "public_key" => $activeProvider->public_key ?? '',
            "location_id" => $activeProvider->location_id ?? '',
            "environment" => $activeProvider->environment ?? 'sandbox',
            "currency" => $activeProvider->currency ?? 'USD',
        ] : null,
        "currency_code" => $currencyCode,
        "payment_provider_name" => $paymentProviderName,
        "current_status" => $order->status_workflow,
        "square_application_id" => ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->public_key ?? '') : '',
        "square_location_id" => ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->location_id ?? '') : '',
        "square_environment" => ($activeProvider && $activeProvider->provider_type === 'square') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox',
        "stripe_publishable_key" => ($activeProvider && $activeProvider->provider_type === 'stripe') ? ($activeProvider->public_key ?? '') : '',
        "paypal_client_id" => ($activeProvider && $activeProvider->provider_type === 'paypal') ? ($activeProvider->api_key ?? '') : '',
        "paypal_environment" => ($activeProvider && $activeProvider->provider_type === 'paypal') ? ($activeProvider->environment ?? 'sandbox') : 'sandbox',
        "payment_request" => $paymentRequest,
        "can_use_saved_payment_methods" => $savedPaymentContext["can_use_saved_payment_methods"] ?? false,
        "saved_payment_methods" => $savedPaymentContext["saved_payment_methods"] ?? [],
        "supports_future_payment_methods" => $savedPaymentContext["supports_future_payment_methods"] ?? false,
        "payment_consent_text" => $savedPaymentContext["payment_consent_text"] ?? '',
        "payment_consent_version" => $savedPaymentContext["payment_consent_version"] ?? '',
        "is_event_past" => $isEventPast,
        "tip_success" => $tipSuccess,
        "last_tip_payment" => $lastTipPayment,
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

    if (!$decoded || !isset($decoded["order_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        return Response::createResponse(TranslationService::trans('planner_hub.invalid_token'));
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "order_id" => $decoded["order_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        return Response::createResponse(TranslationService::trans('planner_hub.invalid_token'));
    }

    $orderId = intval($decoded["order_id"]);
    $userId = intval($decoded["user_id"]);

    $orderRepo = new OrdersRepository();
    $docRepo = new DocumentsLogsRepository();
    $notificationsRepo = new NotificationsRepository();

    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if ($order) {
        $order = (object)$order;
    }
    
    if (!$order)
        return Response::createResponse(TranslationService::trans('planner_hub.no_orders_found'));

    if ($docRepo->getByType($orderId, 'contract_signed')) {
        return Response::createResponse(TranslationService::trans('ui.contract_already_signed'));
    }

    if (isset($_POST["docusign_sign"]) && $_POST["docusign_sign"] == "1") {
        try {
            $docuSignService = new DocuSignService();
            
            if (!$docuSignService->isConfigured()) {
                return Response::createResponse("DocuSign is not configured");
            }
            
            $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
            if ($order) {
                $order = (object)$order;
            }
            
            if (!$order) {
                return Response::createResponse("Order not found");
            }
            
            $userRepo = new \App\Repositories\UserRepository();
            $client = $userRepo->getOne(["id" => $userId]);
            
            if (!$client) {
                return Response::createResponse("Client not found");
            }
            
            $pdfPath = null;
            try {
                $contractRepo = new \App\Repositories\OrdersContractRepository();
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                
                $institution = $institutionRepo->getByOwner($order->id_owner);
                $institution = json_decode(json_encode($institution), true);
                $contract = $order->id_contract ? $contractRepo->getByIdWithoutOwnershipCheck($order->id_contract) : null;
                
                $ip = ($_SERVER["REMOTE_ADDR"] === '::1') ? '127.0.0.1' : ($_SERVER["REMOTE_ADDR"] ?? 'Unknown');
                $browser = $_SERVER["HTTP_USER_AGENT"] ?? 'Unknown';
                $userTimestamp = $_POST["user_local_timestamp"] ?? null;
                if ($userTimestamp) {
                    $timestamp = date("F j, Y - g:i A", strtotime($userTimestamp));
                } else {
                    $timestamp = date("F j, Y - g:i A");
                }
                
                $logoBase64 = '';
                $institutionName = $institution["name"] ?? "";
                $institutionAddress = $institution["address"] ?? "";
                $institutionPhone = $institution["phone"] ?? "";
                $institutionEmail = $institution["email"] ?? "";
                
                if ($institution && !empty($institution['logo_path'])) {
                    $logoPath = $institution['logo_path'];
                    if (strpos($logoPath, 'res.cloudinary.com') !== false && 
                        strpos($logoPath, 'http') === false) {
                        $logoPath = 'https://' . ltrim($logoPath, '/');
                    }
                    
                    try {
                        $context = stream_context_create([
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                            ],
                            'http' => [
                                'ignore_errors' => true,
                                'timeout' => 10
                            ]
                        ]);
                        
                        $imageData = file_get_contents($logoPath, false, $context);
                        if ($imageData !== false) {
                            $imageInfo = @getimagesizefromstring($imageData);
                            if ($imageInfo) {
                                $mimeType = $imageInfo['mime'];
                                $logoBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                            }
                        }
                    } catch (\Exception $e) {
                    }
                }
                
                $html = '
                <html>
                <head>
                    <meta charset="UTF-8">
                    <style>
                        @page { margin: 50px 50px; }
                        body { font-family: Arial, sans-serif; color: #152026; font-size: 9px; margin: 0; padding: 0; }
                        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
                        .company-info { color: #152026; font-size: 8px; }
                        .company-name { font-weight: bold; font-size: 8px; }
                        .invoice-info { text-align: right; color: #152026; font-size: 8px; }
                        .invoice-number { font-weight: bold; font-size: 8px; }
                        .hr-thick { height: 4px; background: #4c6b7d; margin: 15px 0; }
                        .title { font-size: 20px; font-weight: bold; color: #152026; margin: 20px 0 5px; }
                        .subtitle { font-size: 9px; color: #152026; margin-bottom: 20px; }
                        .grid-4 { display: table; width: 100%; margin-bottom: 15px; }
                        .grid-row { display: table-row; }
                        .grid-cell { display: table-cell; width: 25%; border: 1px solid #d6dde3; padding: 8px; background: #f8f9fa; vertical-align: top; }
                        .grid-header { font-weight: bold; color: #152026; font-size: 9px; margin-bottom: 3px; }
                        .grid-content { color: #152026; font-size: 9px; line-height: 1.2; }
                        .contract-content { margin: 20px 0; line-height: 1.5; font-size: 11px; }
                        .meta { margin-top: 20px; font-size: 8px; color: #6b7a85; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <div style="display: flex; align-items: center;">
                            ' . (!empty($logoBase64) ? 
                                '<img src="' . $logoBase64 . '" alt="Logo" style="height: 40px; margin-right: 15px;">' : '') . '
                            <div class="company-info">
                                <div class="company-name">' . htmlspecialchars($institutionName) . '</div>
                                <div>' . htmlspecialchars($institutionEmail) . ' | ' . htmlspecialchars($institutionPhone) . '</div>
                            </div>
                        </div>
                        <div class="invoice-info">
                            <div class="invoice-number">Contract #VNV-341' . $order->id . '</div>
                            <div>Generated</div>
                            <div>' . $timestamp . '</div>
                        </div>
                    </div>

                    <div class="hr-thick"></div>

                    <div class="title">Service Agreement</div>
                    <div class="subtitle">This agreement covers the services for order VNV-341' . $order->id . '.</div>

                    <div class="grid-4">
                        <div class="grid-row">
                            <div class="grid-cell">
                                <div class="grid-header">Client</div>
                                <div class="grid-content">' . htmlspecialchars($client->name . ' ' . $client->lastname) . '</div>
                                <div class="grid-content">' . htmlspecialchars($institutionName) . '</div>
                                <div class="grid-content">' . htmlspecialchars($client->email) . '</div>
                                <div class="grid-content">' . htmlspecialchars($client->phone ?? '') . '</div>
                            </div>
                            <div class="grid-cell">
                                <div class="grid-header">Contract Information</div>
                                <div class="grid-content">Order ID: VNV-341' . $order->id . '</div>
                                <div class="grid-content">Venue: ' . htmlspecialchars($order->address ?? "") . '</div>
                                <div class="grid-content">Generated: ' . $timestamp . '</div>
                            </div>
                            <div class="grid-cell">
                                <div class="grid-header">Event Date</div>
                                <div class="grid-content">' . date("F j, Y", strtotime($order->event_date)) . '</div>
                            </div>
                            <div class="grid-cell">
                                <div class="grid-header">Signature</div>
                                <div class="grid-content">Electronically signed</div>
                                <div class="grid-content">IP: ' . $ip . '</div>
                                <div class="grid-content">' . $timestamp . '</div>
                            </div>
                        </div>
                    </div>

                    <div class="contract-content">
                        ' . ($contract ? $contract->content : '<p>No contract assigned.</p>') . '
                    </div>

                    <div class="meta">
                        <hr style="height:1px; background:#d6dde3; border:0; margin:15px 0;"/>
                        <div>This document was electronically generated and signed by VNV Events LLC.</div>
                        <div>Browser: ' . htmlspecialchars($browser) . '</div>
                    </div>
                </body>
                </html>';
                
                $options = new \Dompdf\Options();
                $options->set('isHtml5ParserEnabled', true);
                $options->set('defaultFont', 'Arial');
                
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4');
                $dompdf->render();
                
                $pdfContent = $dompdf->output();
                
                $tempFileName = 'docusign_contract_' . $orderId . '_' . time() . '.pdf';
                $pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempFileName;
                file_put_contents($pdfPath, $pdfContent);
                
            } catch (\Exception $e) {
                return Response::createResponse("Failed to generate PDF: " . $e->getMessage());
            }
            
            if (!$pdfPath || !file_exists($pdfPath)) {
                return Response::createResponse("Failed to generate PDF - file not found");
            }
            
            $result = $docuSignService->createEnvelope(
                $pdfPath,
                $client->email,
                $client->name . ' ' . $client->lastname,
                $orderId,
                $token
            );
            
            @unlink($pdfPath);
            
            if ($result && isset($result['recipientViewUrl'])) {
                header("Location: " . $result['recipientViewUrl']);
                exit;
            } else {
                return Response::createResponse("Failed to create DocuSign envelope");
            }
        } catch (\Exception $e) {
            return Response::createResponse("Error processing DocuSign request: " . $e->getMessage());
        }
    }
 
    $userLocalTimestamp = $_POST["user_local_timestamp"] ?? null;
    $signatureMethod = !empty($_FILES["signature_image"]["tmp_name"]) ? 'upload' : 'initials';

    if (empty($_POST["e_sign_consent"])) {
        return Response::createResponse(TranslationService::trans('ui.e_sign_consent_required'));
    }

    if (!empty($_FILES["signature_image"]["tmp_name"])) {
        FileUtils::saveFile($_FILES["signature_image"], "files/contracts/");
    }
    if (empty($_FILES["signature_image"]["tmp_name"]) && empty(trim($_POST["typed_initials"] ?? '')))
        return Response::createResponse(TranslationService::trans('planner_hub.no_signature_provided'));

    $result = ContractPdfGenerator::generateAndSave($order->id, $userLocalTimestamp);
    $filename = is_array($result) ? ($result['file_path'] ?? '') : (string)$result;
    $contentHash = is_array($result) ? ($result['hash'] ?? hash('sha256', $filename)) : hash('sha256', $filename);

    if (!$filename)
        return Response::createResponse(TranslationService::trans('planner_hub.no_signature_provided'));

    $generatedAt = $userLocalTimestamp ?: date("Y-m-d H:i:s");

    $docResult = $docRepo->add([
        "id_order" => $orderId,
        "id_user" => $userId,
        "doc_type" => "contract_signed",
        "file_path" => $filename,
        "hash" => $contentHash,
        "ip" => $_SERVER["REMOTE_ADDR"] ?? '',
        "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? '',
        "extra" => json_encode([
            "method" => $signatureMethod,
            "e_sign_consent" => true,
            "document_id" => "VNV-341" . $orderId,
        ]),
        "generated_at" => $generatedAt
    ]);

    $orderRepo->update(
        ["status_workflow" => "INVOICE_READY"],
        ["id" => $orderId]
    );

    $statusHistoryRepo = new \App\Repositories\OrdersStatusHistoryRepository();
    $statusHistoryRepo->add([
        "id_order" => $orderId,
        "status" => "INVOICE_READY",
        "action_type" => "contract_signed",
        "file_path" => $filename,
        "note" => TranslationService::trans('planner_hub.contract_signed_by_client', ['hash' => substr($contentHash, 0, 16)]),
        "created_by" => $userId,
    ]);

    $publicOrderUrl = ($_ENV["APP_URL"] ?? "vnv-venue") . "/order-access?token=" . $token;

    // Notificación al propietario de la orden
    $notificationsRepo->add([
        "id_user" => $order->id_owner,
        "mensaje" => "✍️ " . TranslationService::trans('planner_hub.contract_signed', ['order_id' => $order->id]),
        "link" => $publicOrderUrl,
        "leido" => 0
    ]);

    $notificationsRepo->add([
        "id_user" => $userId,
        "mensaje" => "✅ " . TranslationService::trans('planner_hub.contract_signed_successfully', ['order_id' => $order->id]),
        "link" => $publicOrderUrl,
        "leido" => 0
    ]);

    // Envío de correo al cliente (usar SMTP del owner de la orden)
    try {
        // Usar el id_owner de la orden para las credenciales SMTP del owner (panel/planner-hub/settings/smtp)
        $emailService = new EmailService($order->id_owner);
        
        // Obtener información del cliente (sin filtro de ownership)
        $userRepo = new \App\Repositories\UserRepository();
        $client = $userRepo->getOneWithoutOwnership(["id" => $userId]);
        
        if ($client && $client->email) {
            // Obtener el idioma del sistema del owner para el correo
            $owner = $userRepo->getOneWithoutOwnership(["id" => $order->id_owner]);
            // Acceder directamente a la propiedad system_language del objeto de BD
            $systemLanguage = ($owner && isset($owner->system_language) && !empty($owner->system_language)) ? $owner->system_language : 'en';
            
            // Establecer el locale para el correo según el system_language del owner
            TranslationService::setLocale($systemLanguage);
            
            $subject = "VNV-Events - ✅ " . TranslationService::trans('planner_hub.contract_signed_successfully', ['order_id' => $order->id]);
            
            // Preparar datos para el template (incluir traducciones)
            $templateData = [
                'orderId' => $order->id,
                'eventDate' => date("F j, Y", strtotime($order->event_date)),
                'eventTime' => date("g:i A", strtotime($order->start_time)) . ' to ' . date("g:i A", strtotime($order->end_time)),
                'location' => $order->address,
                'orderUrl' => ($_ENV["APP_URL"] ?? "http://localhost/vnv-venue") . "/order-access?token=" . urlencode($token),
                'locale' => $systemLanguage // Pasar el locale al template
            ];
            
            // Usar template de correo usando helper similar al path() de Twig
            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/contract_signed.php");
            $emailResult = $emailService->sendTemplateEmail(
                $client->email,
                $subject,
                $templatePath,
                $templateData
            );
            
            if ($emailResult) {
                error_log("Contract signed email sent successfully to: " . $client->email);
            } else {
                error_log("Failed to send contract signed email to: " . $client->email . " - Debug: " . $emailService->getDebugInfo());
            }
        } else {
            error_log("Client email not found for user ID: " . $userId);
        }
        
    } catch (\Throwable $e) {
        error_log("Error sending contract signed email: " . $e->getMessage());
    }

    LocationUtils::redirectInternal("order-access?token=" . urlencode($token) . "&t=" . time());
});

$router->run();
