<?php

use App\Services\LoginService;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersContractRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Repositories\OrdersStatusHistoryRepository;
use App\Services\NotificationService;
use App\Services\EmailService;
use App\Repositories\NotificationsRepository;
use App\Repositories\TipsRepository;

$router = new Router();

$router->get(function () {
    $serviceRepo = new OrdersServiceRepository();
    $contractRepo = new OrdersContractRepository();
    $userRepo = new UserRepository();
    $tipsRepo = new TipsRepository();
    $user = LoginService::getSession();

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $services = $serviceRepo->getAllByInstitutionOwner($institution->id_owner, 0);
                $contracts = $contractRepo->getAllByInstitutionOwner($institution->id_owner);
                $clients = $userRepo->getAllAssociatedClients($institution->id_owner);
            } else {
                $services = [];
                $contracts = [];
                $clients = [];
            }
        } else {
            $services = [];
            $contracts = [];
            $clients = [];
        }
    } else {
        $services = $serviceRepo->getAllBy([
            "id_owner" => $user->getOwner(),
            "is_archived" => 0,
        ]);

        $contracts = $contractRepo->getAllBy([
            "id_owner" => $user->getOwner(),
        ]);

        $clients = $userRepo->getAllBy([
            "level" => 5,
            "id_owner" => $user->getOwner(),
        ]);
    }

    $tips = $tipsRepo->getActiveTips();

    $parentOrderId = $_GET["parent_order"] ?? null;
    $parentOrder = null;
    $isSubOrder = false;

    if ($parentOrderId) {
        $orderRepo = new OrdersRepository();
        
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                if ($institution && $institution->id_owner) {
                    $parentOrder = $orderRepo->getOneByIdAndOwner($parentOrderId, $institution->id_owner);
                    if ($parentOrder) {
                        $isSubOrder = true;
                    }
                }
            }
        } else {
            $parentOrder = $orderRepo->getOne(["id" => $parentOrderId]);
            if ($parentOrder && $parentOrder->id_owner == $user->getOwner()) {
                $isSubOrder = true;
            }
        }
    }

    $prefillEmail = $_GET['client_email'] ?? null;
    $prefillClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    $prefillClient = null;
    if ($prefillClientId > 0) {
        $candidateClient = $userRepo->getOneWithoutOwnership(["id" => $prefillClientId, "level" => 5]);
        if ($candidateClient && (!$prefillEmail || strcasecmp((string)$candidateClient->email, (string)$prefillEmail) === 0)) {
            $prefillClient = $candidateClient;
            $prefillEmail = $candidateClient->email;
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "services" => $services,
        "contracts" => $contracts,
        "clients" => $clients,
        "statuses" => OrdersRepository::PAYMENT_STATUSES,
        'base_url' => $_ENV["APP_URL"],
        "parentOrder" => $parentOrder,
        "isSubOrder" => $isSubOrder,
        "prefillEmail" => $prefillEmail,
        "prefillClient" => $prefillClient,
        "tips" => $tips
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();

    $parentOrderId = $_POST["parent_order_id"] ?? null;
    $isSubOrder = !empty($parentOrderId);

    if ($isSubOrder) {
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                if ($institution && $institution->id_owner) {
                    $parentOrder = $orderRepo->getOneByIdAndOwner($parentOrderId, $institution->id_owner);
                } else {
                    $parentOrder = null;
                }
            } else {
                $parentOrder = null;
            }
        } else {
            $parentOrder = $orderRepo->getOne(["id" => $parentOrderId]);
            if ($parentOrder && $parentOrder->id_owner != $user->getOwner()) {
                $parentOrder = null;
            }
        }
        
        if (!$parentOrder) {
            MessageUtil::setMessage("Parent order not found or access denied.");
            LocationUtils::reload();
        }

        $client = $parentOrder->id_client;
        $date = $parentOrder->event_date;
        $address = $parentOrder->address;
        $hourStart = $parentOrder->start_time;
        $hourEnd = $parentOrder->end_time;
        $id_contract = $parentOrder->id_contract;
        $totalTeamNeeded = $parentOrder->total_team_needed;
    } else {
        $client = $_POST["id_client"] ?? null;
        $date = $_POST["event_date"] ?? null;
        $today = date("Y-m-d");
        if ($date < $today) {
            MessageUtil::setMessage("âŒ Event date cannot be in the past.");
            LocationUtils::reload();
        }

        $address = $_POST["address"] ?? "";
        $hourStart = $_POST["hour_start"] ?? "";
        $hourEnd = $_POST["hour_end"] ?? "";
        $id_contract = $_POST["id_contract"] ?? null;
        $totalTeamNeeded = isset($_POST["total_team_needed"]) ? (int) $_POST["total_team_needed"] : 0;
    }

    $discount_type = $_POST["discount_type"] ?? "amount";
    $discount_value = $_POST["discount_value"] ?? 0;
    $tax_percentage = $_POST["tax_percentage"] ?? 0;
    $id_tip = !empty($_POST["id_tip"]) ? $_POST["id_tip"] : null;
    $notes = $_POST["notes"] ?? "";
    $services = $_POST["selectedServices"] ?? "";
    $userLocalTimestamp = $_POST["user_local_timestamp"] ?? null;

    $services = json_decode($services, true);

    if (!$client || !$date || !$address || !$hourStart || !$hourEnd || !$id_contract || empty($services)) {
        MessageUtil::setMessage("Missing required fields.");
        LocationUtils::reload();
    }

    $subtotal = 0;
    foreach ($services as $service) {
        $subtotal += (float)$service['subtotal'];
    }

    $actual_discount_value = $discount_value;
    if ($discount_type === 'percent') {
        $actual_discount_value = $subtotal * ($discount_value / 100);
    }
    
    $db_discount_type = ($discount_type === 'percent') ? 'percentage' : 'amount';

    $orderId = null;
    $suborderId = null;
    $message = "";
    $notificationMessage = "";
    $creationSuccess = false;

    if ($isSubOrder) {
        $suborderData = [
            'tax_percentage' => $tax_percentage,
            'payment_split_type' => $_POST["payment_split_type"] ?? 2,
            'payment_split_percent_1' => $_POST["payment_split_percent_1"] ?? 50,
            'payment_split_percent_2' => $_POST["payment_split_percent_2"] ?? 50,
            'discount_type' => $db_discount_type,
            'discount_value' => $actual_discount_value
        ];

        try {
            $suborderId = $suborderRepo->createSuborder($parentOrderId, $suborderData);
            
            $suborderOwnerId = $user->getOwner();
            
            if ($user->getLevel() === 4) {
                $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
                if ($currentInstitutionId) {
                    $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                    $institution = $institutionRepo->getById($currentInstitutionId);
                    if ($institution && $institution->id_owner) {
                        $suborderOwnerId = $institution->id_owner;
                    }
                }
            }
            
            foreach ($services as $item) {
                // Obtener el precio y descripciÃ³n del servicio para guardarlo como histÃ³rico
                $serviceRepo = new OrdersServiceRepository();
                $service = $serviceRepo->getByIdWithoutOwnershipCheck($item["service"]);
                $unitPrice = ($item["is_variable"] ?? "NO") === "YES" && isset($item["variable_price"]) 
                    ? $item["variable_price"] 
                    : ($service ? $service->price : 0);
                $description = $service ? ($service->description ?? null) : null;
                
                $suborderServicesRepo->add([
                    "id_suborder" => $suborderId,
                    "id_service" => $item["service"],
                    "quantity" => $item["qty"],
                    "unit_price" => $unitPrice,
                    "description" => $description,
                    "subtotal" => $item["subtotal"],
                    "id_owner" => $suborderOwnerId,
                    "is_variable" => $item["is_variable"] ?? "NO",
                    "variable_price" => $item["variable_price"] ?? null
                ]);
            }
            
            $orderId = $parentOrderId;
            $message = "Sub-order created successfully for order VNV 341{$parentOrderId}!";
            $notificationMessage = "Sub-order has been created for order VNV 341{$parentOrderId}. Please log in to your account to view the details.";
            $creationSuccess = true;
            
        } catch (\Throwable $e) {
            MessageUtil::setMessage("Error creating suborder: " . $e->getMessage());
            LocationUtils::reload();
        }
    } else {
        $ownerData = LoginService::getOwnerAsArray();
        $userIdData = LoginService::getUserIdAsArray(true);
        
        if ($user->getLevel() === 4) {
            $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
            
            if ($currentInstitutionId) {
                $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
                $institution = $institutionRepo->getById($currentInstitutionId);
                
                if ($institution && $institution->id_owner) {
                    $ownerData = ['id_owner' => $institution->id_owner];
                    $userIdData = ['id_user' => $institution->id_owner];
                }
            }
        }
        
        $orderData = [
            ...$ownerData,
            ...$userIdData,
            "id_client" => $client,
            "event_date" => $date,
            "address" => $address,
            "start_time" => $hourStart,
            "end_time" => $hourEnd,
            "id_contract" => $id_contract,
            "payment_status" => "paid_full",
            "discount_type" => $discount_type,
            "discount_value" => $actual_discount_value,
            "tax_percentage" => $tax_percentage,
            "id_tip" => $id_tip,
            "notes" => $notes,
            "payment_split_type" => $_POST["payment_split_type"] ?? 2,
            "payment_split_percent_1" => $_POST["payment_split_percent_1"] ?? 50,
            "payment_split_percent_2" => $_POST["payment_split_percent_2"] ?? 50,
            "total_team_needed" => $totalTeamNeeded
        ];

        if ($userLocalTimestamp) {
            $orderData["created_at"] = $userLocalTimestamp;
        }

        try {
            if ($user->getLevel() === 4 && isset($ownerData['id_owner']) && $ownerData['id_owner'] != $user->getOwner()) {
                $orderId = $orderRepo->addWithExplicitOwner($orderData);
            } else {
                $orderRepo->add($orderData);
                $orderId = $orderRepo->getLastId();
            }

            $serviceOwnerId = $ownerData['id_owner'] ?? $user->getOwner();
            
            foreach ($services as $item) {
                // Obtener el precio y descripciÃ³n del servicio para guardarlo como histÃ³rico
                $serviceRepo = new OrdersServiceRepository();
                $service = $serviceRepo->getByIdWithoutOwnershipCheck($item["service"]);
                $unitPrice = ($item["is_variable"] ?? "NO") === "YES" && isset($item["variable_price"]) 
                    ? $item["variable_price"] 
                    : ($service ? $service->price : 0);
                $description = $service ? ($service->description ?? null) : null;
                
                $assignedRepo->add([
                    "id_order" => $orderId,
                    "id_service" => $item["service"],
                    "quantity" => $item["qty"],
                    "unit_price" => $unitPrice,
                    "description" => $description,
                    "subtotal" => $item["subtotal"],
                    "id_owner" => $serviceOwnerId,
                    "is_variable" => $item["is_variable"] ?? "NO",
                    "variable_price" => $item["variable_price"] ?? null
                ]);
            }

            $statusWorkflow = "INVOICE_DRAFT"; 

            $historyRepo = new OrdersStatusHistoryRepository();

            $historyRepo->add([
                "id_order" => $orderId,
                "status" => $statusWorkflow,
                "action_type" => "manual_change",
                "note" => "Invoice approval requires client signature.",
                "created_by" => $user->getId()
            ]);

            $orderRepo->update([
                "status_workflow" => $statusWorkflow
            ], ["id" => $orderId]);

            $message = "Order created successfully!";
            $notificationMessage = "New order VNV 341{$orderId} has been created. Please log in to your account to view the details.";
            $creationSuccess = true;

            $shouldAddCalendar = isset($_POST['add_to_calendar']) && $_POST['add_to_calendar'] == '1';
            if ($shouldAddCalendar) {
                MessageUtil::setMessage($message);
                $clientId = $client;
                LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/?add_calendar=1&order_id=" . urlencode((string)$orderId) . "&client_id=" . urlencode((string)$clientId));
            }
            
        } catch (\Throwable $e) {
            MessageUtil::setMessage("Error creating order: " . $e->getMessage());
            LocationUtils::reload();
        }
    }

    if ($creationSuccess && $orderId) {
        $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
        $payload = [
            "order_id" => (int)$orderId,
            "user_id" => (int)$client,
            "exp" => time() + 60 * 60 * 24 * 30,
        ];
        $payload["hash"] = hash_hmac("sha256", json_encode([
            "order_id" => $payload["order_id"],
            "user_id" => $payload["user_id"],
            "exp" => $payload["exp"]
        ]), $secret);
        $orderToken = base64_encode(json_encode($payload));
        
        $clientOrderUrl = \App\Utils\LocationUtils::pathFor("/order-access?token=" . urlencode($orderToken));
        $ownerOrderUrl = \App\Utils\LocationUtils::pathFor("/panel/planner-hub/management/orders/orders/");

        try {
            $notificationsRepo = new NotificationsRepository();

            $notificationsRepo->add([
                "id_user" => $client,
                "mensaje" => $notificationMessage,
                "link" => $clientOrderUrl,
                "leido" => 0
            ]);

            $notificationsRepo->add([
                "id_user" => $user->getOwner(),
                "mensaje" => $notificationMessage,
                "link" => $ownerOrderUrl,
                "leido" => 0
            ]);
        } catch (\Throwable $e) {
            error_log("Order notification failed after create: " . $e->getMessage());
        }
    }

    if ($creationSuccess && $orderId) {
        try {
            $emailService = new EmailService();
            
            $userRepo = new UserRepository();
            $clientInfo = $userRepo->getOne(["id" => $client]);
            
            if ($clientInfo && $clientInfo->email) {
                if ($isSubOrder) {
                    $subject = "ðŸ“ New Sub-Order Created - Sub-Order #" . $suborderId . " for Order #VNV341" . $parentOrderId;
                } else {
                    $subject = "ðŸ“ New Order Created - Order #VNV341" . $orderId;
                }
                
                if ($isSubOrder) {
                $assignedServices = $suborderServicesRepo->getServicesWithDetails($suborderId);
                $servicesForEmail = [];
                
                foreach ($assignedServices as $assigned) {
                    $unitPrice = ($assigned->is_variable === 'YES' && $assigned->variable_price !== null) 
                        ? $assigned->variable_price 
                        : $assigned->service_price;
                    
                    $servicesForEmail[] = [
                        'name' => $assigned->service_name,
                        'quantity' => $assigned->quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $assigned->quantity * $unitPrice
                    ];
                }
            } else {
                $assignedServices = $assignedRepo->getAllBy(["id_order" => $orderId]);
                $servicesForEmail = [];
                $serviceRepo = new OrdersServiceRepository();
                
                foreach ($assignedServices as $assigned) {
                    $service = $serviceRepo->getOne(["id" => $assigned->id_service]);
                    if ($service) {
                        // Usar el precio histÃ³rico almacenado (unit_price) si existe
                        if (isset($assigned->unit_price) && $assigned->unit_price > 0) {
                            $unitPrice = $assigned->unit_price;
                        } else {
                            // Fallback para Ã³rdenes antiguas
                            $unitPrice = ($assigned->is_variable === 'YES' && $assigned->variable_price !== null) 
                                ? $assigned->variable_price 
                                : $service->price;
                        }
                        
                        $servicesForEmail[] = [
                            'name' => $service->name,
                            'quantity' => $assigned->quantity,
                            'unit_price' => $unitPrice,
                            'subtotal' => $assigned->quantity * $unitPrice
                        ];
                    }
                }
            }
            
            $subtotal = 0;
            foreach ($servicesForEmail as $service) {
                $subtotal += $service['subtotal'];
            }
            
            if ($isSubOrder) {
                $discountType = $db_discount_type;
                $discountValue = $actual_discount_value;
                $actualDiscount = ($discountType === 'percentage') ? $subtotal * ($discountValue / 100) : $discountValue;
                
                $base = max($subtotal - $actualDiscount, 0);
                $taxRate = $tax_percentage;
                $tax = $base * ($taxRate / 100);
                $totalAmount = $base + $tax;
            } else {
                $discountType = $db_discount_type;
                $discountValue = $actual_discount_value;
                $actualDiscount = ($discountType === 'percentage') ? $subtotal * ($discountValue / 100) : $discountValue;
                
                $base = max($subtotal - $actualDiscount, 0);
                $taxRate = $tax_percentage;
                $tax = $base * ($taxRate / 100);
                $totalAmount = $base + $tax;
            }
            
            $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
            if ($isSubOrder) {
                $payload = [
                    "order_id" => $parentOrderId,
                    "user_id" => $client,
                    "exp" => time() + 60 * 60 * 24 * 30,
                ];
            } else {
                $payload = [
                    "order_id" => $orderId,
                    "user_id" => $client,
                    "exp" => time() + 60 * 60 * 24 * 30,
                ];
            }
            $payload["hash"] = hash_hmac("sha256", json_encode([
                "order_id" => $payload["order_id"],
                "user_id" => $payload["user_id"],
                "exp" => $payload["exp"]
            ]), $secret);
            $orderToken = base64_encode(json_encode($payload));
            
            if ($isSubOrder) {
                $templateData = [
                    'orderId' => $parentOrderId,
                    'subOrderId' => $suborderId,
                    'eventDate' => date("F j, Y", strtotime($date)),
                    'eventTime' => date("g:i A", strtotime($hourStart)) . ' to ' . date("g:i A", strtotime($hourEnd)),
                    'location' => $address,
                    'totalAmount' => $totalAmount,
                    'services' => $servicesForEmail,
                    'orderUrl' => $clientOrderUrl,
                    'isSubOrder' => true
                ];
            } else {
                $templateData = [
                    'orderId' => $orderId,
                    'eventDate' => date("F j, Y", strtotime($date)),
                    'eventTime' => date("g:i A", strtotime($hourStart)) . ' to ' . date("g:i A", strtotime($hourEnd)),
                    'location' => $address,
                    'totalAmount' => $totalAmount,
                    'services' => $servicesForEmail,
                    'orderUrl' => $clientOrderUrl,
                    'isSubOrder' => false
                ];
            }
            
            $templatePath = \App\Utils\LocationUtils::getTemplatePath("emails/new_order.php");
            
            $emailService->sendTemplateEmail(
                $clientInfo->email,
                $subject,
                $templatePath,
                $templateData
            );
            }
        } catch (\Throwable $e) {
            error_log("Order email failed after create: " . $e->getMessage());
        }
    }

    if ($creationSuccess) {
        MessageUtil::setMessage($message);
        
        if ($isSubOrder) {
            LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/suborders/?id={$parentOrderId}");
        } elseif (isset($statusWorkflow) && $statusWorkflow === "INVOICE_DRAFT") {
            LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/?tab=estimates");
        } else {
            LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/");
        }
    }
});

$router->run();



