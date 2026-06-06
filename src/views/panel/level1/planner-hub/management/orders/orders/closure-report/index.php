<?php

use App\Repositories\OrdersRepository;
use App\Repositories\DocumentsLogsRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServiceTasksRepository;
use App\Repositories\OrdersTeamTasksRepository;
use App\Repositories\OrdersTeamTaskPhotosRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersClosureCommunicationsRepository;
use App\Repositories\OrdersTeamOrderPhotosRepository;
use App\Services\ClosureCommunicationsPdfGenerator;
use App\Utils\FileUtils;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $orderId = (int)($_GET["id"] ?? 0);

    if (!$orderId) {
        MessageUtil::setMessage("Order ID is required");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/previous");
        return;
    }

    // Verificar acceso a la orden
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
        MessageUtil::setMessage("Order not found or access denied");
        LocationUtils::redirectInternal("panel/planner-hub/management/orders/orders/previous");
        return;
    }

    // Obtener datos existentes
    $docRepo = new DocumentsLogsRepository();
    $assignedRepo = new OrdersServicesAssignedRepository();
    $serviceRepo = new OrdersServiceRepository();
    $taskRepo = new OrdersServiceTasksRepository();
    $teamTaskRepo = new OrdersTeamTasksRepository();
    $photosRepo = new OrdersTeamTaskPhotosRepository();
    $userRepo = new UserRepository();
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $communicationsRepo = new OrdersClosureCommunicationsRepository();
    $teamOrderPhotosRepo = new OrdersTeamOrderPhotosRepository();
    
    // Obtener información del cliente
    $client = null;
    if ($order->id_client) {
        $client = $userRepo->getOne(["id" => $order->id_client]);
    }

    // 1. Service Execution Proof - Obtener fotos de tareas del equipo agrupadas por usuario y servicio
    $servicesAssigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
    $servicesWithPhotos = [];
    $allAssignedUsers = [];
    
    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);
        $photosByUser = [];
        $tasksWithNoPhotos = [];
        $tasksWithPhotos = [];
        
        foreach ($tasks as $task) {
            $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
                "id_order" => $orderId,
                "id_service" => $assigned->id_service,
                "task_description" => $task->task_name
            ]);
            
            if ($assignedTask?->id) {
                $assignedUser = $userRepo->getOne(["id" => $assignedTask->id_user]);
                $assignedUserName = $assignedUser ? $assignedUser->name . ' ' . $assignedUser->lastname : 'Unknown';
                $assignedUserId = $assignedTask->id_user;
                
                $taskPhotos = $photosRepo->getByTask($assignedTask->id);
                
                if (!empty($taskPhotos)) {
                    $tasksWithPhotos[] = [
                        'task_name' => $task->task_name,
                        'task_id' => $task->id,
                        'is_done' => $assignedTask->is_done ?? 0
                    ];
                    
                    foreach ($taskPhotos as $photo) {
                        // Usar el usuario que subió la foto (uploaded_by), no el asignado a la tarea
                        $uploadedByUserId = $photo->uploaded_by ?? $assignedUserId;
                        $uploadedByUser = $userRepo->getOne(["id" => $uploadedByUserId]);
                        $uploadedByUserName = $uploadedByUser ? $uploadedByUser->name . ' ' . $uploadedByUser->lastname : 'Unknown';
                        
                        if (!isset($allAssignedUsers[$uploadedByUserId])) {
                            $allAssignedUsers[$uploadedByUserId] = [
                                'id' => $uploadedByUserId,
                                'name' => $uploadedByUserName,
                                'email' => $uploadedByUser ? $uploadedByUser->email : ''
                            ];
                        }
                        
                        if (!isset($photosByUser[$uploadedByUserId])) {
                            $photosByUser[$uploadedByUserId] = [
                                'user_id' => $uploadedByUserId,
                                'user_name' => $uploadedByUserName,
                                'user_email' => $uploadedByUser ? $uploadedByUser->email : '',
                                'photos' => []
                            ];
                        }
                        
                        $photo->task_name = $task->task_name;
                        $photo->task_id = $task->id;
                        $photo->uploaded_at = $photo->uploaded_at ?? $photo->created_at ?? date('Y-m-d H:i:s');
                        $photosByUser[$uploadedByUserId]['photos'][] = $photo;
                    }
                } else {
                    $tasksWithNoPhotos[] = [
                        'task_name' => $task->task_name,
                        'task_id' => $task->id,
                        'user_id' => $assignedUserId,
                        'user_name' => $assignedUserName,
                        'is_done' => $assignedTask->is_done ?? 0
                    ];
                }
            } else {
                $tasksWithNoPhotos[] = [
                    'task_name' => $task->task_name,
                    'task_id' => $task->id,
                    'user_id' => null,
                    'user_name' => null,
                    'is_done' => 0
                ];
            }
        }
        
        $servicesWithPhotos[] = [
            'service' => $service,
            'assigned' => $assigned,
            'photos_by_user' => array_values($photosByUser),
            'tasks_with_no_photos' => $tasksWithNoPhotos,
            'has_photos' => !empty($photosByUser),
            'total_photos' => array_sum(array_map(fn($user) => count($user['photos']), $photosByUser)),
            'is_suborder' => false
        ];
    }
    
    // Procesar servicios de subórdenes
    $suborders = $suborderRepo->getByOrder($orderId);
    foreach ($suborders as $suborder) {
        $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        
        foreach ($suborderServicesAssigned as $assigned) {
            $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
            $tasks = $taskRepo->getAllBy(["id_service" => $assigned->id_service]);
            $photosByUser = [];
            $tasksWithNoPhotos = [];
            $tasksWithPhotos = [];
            
            foreach ($tasks as $task) {
                $assignedTask = $teamTaskRepo->getOneWithoutOwnershipCheck([
                    "id_suborder" => $suborder->id,
                    "id_service" => $assigned->id_service,
                    "task_description" => $task->task_name
                ]);
                
                if ($assignedTask?->id) {
                    $assignedUser = $userRepo->getOne(["id" => $assignedTask->id_user]);
                    $assignedUserName = $assignedUser ? $assignedUser->name . ' ' . $assignedUser->lastname : 'Unknown';
                    $assignedUserId = $assignedTask->id_user;
                    
                    $taskPhotos = $photosRepo->getByTask($assignedTask->id);
                    
                    if (!empty($taskPhotos)) {
                        $tasksWithPhotos[] = [
                            'task_name' => $task->task_name,
                            'task_id' => $task->id,
                            'is_done' => $assignedTask->is_done ?? 0
                        ];
                        
                        foreach ($taskPhotos as $photo) {
                            // Usar el usuario que subió la foto (uploaded_by), no el asignado a la tarea
                            $uploadedByUserId = $photo->uploaded_by ?? $assignedUserId;
                            $uploadedByUser = $userRepo->getOne(["id" => $uploadedByUserId]);
                            $uploadedByUserName = $uploadedByUser ? $uploadedByUser->name . ' ' . $uploadedByUser->lastname : 'Unknown';
                            
                            if (!isset($allAssignedUsers[$uploadedByUserId])) {
                                $allAssignedUsers[$uploadedByUserId] = [
                                    'id' => $uploadedByUserId,
                                    'name' => $uploadedByUserName,
                                    'email' => $uploadedByUser ? $uploadedByUser->email : ''
                                ];
                            }
                            
                            if (!isset($photosByUser[$uploadedByUserId])) {
                                $photosByUser[$uploadedByUserId] = [
                                    'user_id' => $uploadedByUserId,
                                    'user_name' => $uploadedByUserName,
                                    'user_email' => $uploadedByUser ? $uploadedByUser->email : '',
                                    'photos' => []
                                ];
                            }
                            
                            $photo->task_name = $task->task_name;
                            $photo->task_id = $task->id;
                            $photo->uploaded_at = $photo->uploaded_at ?? $photo->created_at ?? date('Y-m-d H:i:s');
                            $photosByUser[$uploadedByUserId]['photos'][] = $photo;
                        }
                    } else {
                        $tasksWithNoPhotos[] = [
                            'task_name' => $task->task_name,
                            'task_id' => $task->id,
                            'user_id' => $assignedUserId,
                            'user_name' => $assignedUserName,
                            'is_done' => $assignedTask->is_done ?? 0
                        ];
                    }
                } else {
                    $tasksWithNoPhotos[] = [
                        'task_name' => $task->task_name,
                        'task_id' => $task->id,
                        'user_id' => null,
                        'user_name' => null,
                        'is_done' => 0
                    ];
                }
            }
            
            $servicesWithPhotos[] = [
                'service' => $service,
                'assigned' => $assigned,
                'photos_by_user' => array_values($photosByUser),
                'tasks_with_no_photos' => $tasksWithNoPhotos,
                'has_photos' => !empty($photosByUser),
                'total_photos' => array_sum(array_map(fn($user) => count($user['photos']), $photosByUser)),
                'is_suborder' => true,
                'suborder_id' => $suborder->id
            ];
        }
    }

    // 1.4. Team Order Photos (fotos subidas por el equipo en órdenes aceptadas)
    $teamOrderPhotos = $teamOrderPhotosRepo->getByOrder($orderId);
    foreach ($teamOrderPhotos as $p) {
        $uploader = $userRepo->getOne(["id" => $p->id_user]);
        $p->uploader_name = $uploader ? $uploader->name . ' ' . $uploader->lastname : 'Unknown';
    }

    // 1.5. Client Communications
    $communications = $communicationsRepo->getAllByOrder($orderId);
    foreach ($communications as $comm) {
        $createdByUser = $userRepo->getOne(["id" => $comm->created_by]);
        $comm->created_by_name = $createdByUser ? $createdByUser->name . ' ' . $createdByUser->lastname : 'Unknown';
    }

    // 2. Signed Contract
    $signedContracts = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "contract_signed"
    ]);

    // 3. Original Contract Signature (Order Acceptance)
    $acceptanceContracts = $acceptanceRepo->getAllByOrder($orderId);
    $orderAcceptanceSigned = $docRepo->getAllBy([
        "id_order" => $orderId,
        "doc_type" => "order_acceptance_signed"
    ]);

    // 4. Payment Receipts
    $receipts_legacy = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "payment_receipt"]);
    $receipts_first = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "pay_first"]);
    $receipts_second = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "pay_second"]);
    $receipts_full = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "pay_full"]);
    $receipts_sub_first = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "sub_pay_first"]);
    $receipts_sub_second = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "sub_pay_second"]);
    $receipts_sub_full = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "sub_pay_full"]);
    $receipts_tips = $docRepo->getAllBy(["id_order" => $orderId, "doc_type" => "tip_receipt"]);
    
    $allPaymentReceipts = array_merge(
        $receipts_legacy,
        $receipts_first,
        $receipts_second,
        $receipts_full,
        $receipts_sub_first,
        $receipts_sub_second,
        $receipts_sub_full,
        $receipts_tips
    );

    // Formatear recibos para mostrar
    $formattedReceipts = array_map(function ($r) {
        $title = 'Payment Receipt';
        $type = trim($r->doc_type ?? '');
        if ($type === 'pay_first') { $title = 'First Payment Receipt'; }
        if ($type === 'pay_second') { $title = 'Second Payment Receipt'; }
        if ($type === 'pay_full') { $title = 'Full Payment Receipt'; }
        if ($type === 'sub_pay_first') { $title = 'Suborder First Payment Receipt'; }
        if ($type === 'sub_pay_second') { $title = 'Suborder Second Payment Receipt'; }
        if ($type === 'sub_pay_full') { $title = 'Suborder Full Payment Receipt'; }
        if ($type === 'tip_receipt') { $title = '💝 Tip Receipt'; }
        
        return (object) [
            'id' => $r->id,
            'title' => $title,
            'file_path' => $r->file_path,
            'doc_type' => $type,
            'generated_at' => $r->generated_at
        ];
    }, $allPaymentReceipts);

    // Compilar todos los documentos para la vista de lista
    $allDocuments = [];
    
    // Agregar contratos firmados
    foreach ($signedContracts as $contract) {
        $allDocuments[] = [
            'type' => 'contract_signed',
            'title' => 'Signed Contract',
            'file_path' => $contract->file_path,
            'date' => $contract->generated_at,
            'category' => 'Contracts'
        ];
    }
    
    // Agregar aceptación de orden
    foreach ($orderAcceptanceSigned as $acceptance) {
        $allDocuments[] = [
            'type' => 'order_acceptance',
            'title' => 'Order Acceptance Signed',
            'file_path' => $acceptance->file_path,
            'date' => $acceptance->generated_at,
            'category' => 'Contracts'
        ];
    }
    
    foreach ($acceptanceContracts as $acceptance) {
        $allDocuments[] = [
            'type' => 'acceptance_contract',
            'title' => 'Order Acceptance Contract',
            'file_path' => $acceptance->file_path,
            'date' => $acceptance->created_at ?? date('Y-m-d H:i:s'),
            'category' => 'Contracts'
        ];
    }
    
    // Agregar recibos de pago
    foreach ($formattedReceipts as $receipt) {
        $allDocuments[] = [
            'type' => 'payment_receipt',
            'title' => $receipt->title,
            'file_path' => $receipt->file_path,
            'date' => $receipt->generated_at,
            'category' => 'Payments'
        ];
    }
    
    // Agregar fotos del equipo (órdenes aceptadas)
    foreach ($teamOrderPhotos as $i => $p) {
        if (!empty($p->photo_url)) {
            $allDocuments[] = [
                'type' => 'team_order_photo',
                'title' => 'Team Photo ' . ($i + 1) . ' (' . ($p->uploader_name ?? 'Unknown') . ')',
                'file_path' => $p->photo_url,
                'date' => $p->uploaded_at ?? date('Y-m-d H:i:s'),
                'category' => 'Team Photos'
            ];
        }
    }

    // Ordenar por fecha descendente
    usort($allDocuments, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    $generatedPdfUrl = $_SESSION['generated_pdf_url'] ?? null;
    if ($generatedPdfUrl) {
        unset($_SESSION['generated_pdf_url']);
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "order" => $order,
        "client" => $client,
        "communications" => $communications,
        "teamOrderPhotos" => $teamOrderPhotos,
        "servicesWithPhotos" => $servicesWithPhotos,
        "signedContracts" => $signedContracts,
        "acceptanceContracts" => $acceptanceContracts,
        "orderAcceptanceSigned" => $orderAcceptanceSigned,
        "paymentReceipts" => $formattedReceipts,
        "allDocuments" => $allDocuments,
        "generatedPdfUrl" => $generatedPdfUrl
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $formType = $_POST["form_type"] ?? "";
    $orderId = (int)($_POST["order_id"] ?? 0);

    if (!$orderId) {
        MessageUtil::setMessage("Order ID is required", "Error", "error");
        LocationUtils::reload();
        return;
    }

    $orderRepo = new OrdersRepository();
    $order = $orderRepo->getOne(["id" => $orderId]);
    
    if (!$order || ($order->id_user != $user->getId() && $order->id_owner != $user->getOwner())) {
        MessageUtil::setMessage("Order not found or access denied", "Error", "error");
        LocationUtils::reload();
        return;
    }

    $teamOrderPhotosRepo = new OrdersTeamOrderPhotosRepository();

    // Subir fotos del equipo (planner/admin con acceso)
    if ($formType === "upload_photo") {
        if (isset($_FILES["photos"]) && $_FILES["photos"]["error"][0] != UPLOAD_ERR_NO_FILE) {
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            $files = $_FILES["photos"];
            $fileCount = count($files['name']);

            for ($i = 0; $i < $fileCount; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                if (!in_array($files['type'][$i], $allowedTypes)) continue;
                if ($files['size'][$i] > $maxFileSize) continue;

                $fileArray = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];

                try {
                    $photoUrl = FileUtils::saveFile($fileArray, 'team-order-photos');
                    $teamOrderPhotosRepo->add([
                        'id_order' => $orderId,
                        'id_user' => $user->getId(),
                        'photo_url' => $photoUrl
                    ]);
                } catch (\Exception $e) {
                    MessageUtil::setMessage("Error uploading photo: " . $e->getMessage(), "Error", "error");
                }
            }
            MessageUtil::setMessage("Photos uploaded successfully");
        }
        LocationUtils::reload();
        return;
    }

    // Eliminar foto del equipo
    if ($formType === "delete_photo") {
        $photoId = (int)($_POST["photo_id"] ?? 0);
        if ($photoId > 0) {
            $photo = $teamOrderPhotosRepo->getOne(['id' => $photoId]);
            if ($photo && $photo->id_order == $orderId) {
                try {
                    FileUtils::removeFile($photo->photo_url);
                } catch (\Exception $e) {}
                $teamOrderPhotosRepo->delete(['id' => $photoId]);
                MessageUtil::setMessage("Photo deleted successfully");
            }
        }
        LocationUtils::reload();
        return;
    }

    // Manejar diferentes tipos de formularios
    if ($formType === "toggle_closure") {
        $closureEnabled = (int)($_POST["closure_enabled"] ?? 0);
        $orderRepo->update(["closure_enabled" => $closureEnabled], ["id" => $orderId]);
        MessageUtil::setMessage($closureEnabled ? "Closure enabled" : "Closure disabled");
        LocationUtils::reload();
        return;
    }
    
    $communicationsRepo = new OrdersClosureCommunicationsRepository();
    
    if ($formType === "add_communication") {
        $description = trim($_POST["description"] ?? "");
        
        if (empty($description)) {
            MessageUtil::setMessage("Description is required", "Error", "error");
            LocationUtils::reload();
            return;
        }
        
        $photoPath = null;
        if (isset($_FILES["photo"]) && $_FILES["photo"]["error"] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $_FILES["photo"]["type"];
            
            if (in_array($fileType, $allowedTypes)) {
                $fileArray = [
                    'name' => $_FILES["photo"]["name"],
                    'type' => $fileType,
                    'tmp_name' => $_FILES["photo"]["tmp_name"],
                    'error' => $_FILES["photo"]["error"],
                    'size' => $_FILES["photo"]["size"]
                ];
                
                try {
                    $photoPath = FileUtils::saveFile($fileArray, "closure-communications");
                } catch (\Exception $e) {
                    MessageUtil::setMessage("Error uploading photo: " . $e->getMessage(), "Error", "error");
                    LocationUtils::reload();
                    return;
                }
            } else {
                MessageUtil::setMessage("Invalid file type. Only images are allowed.", "Error", "error");
                LocationUtils::reload();
                return;
            }
        }
        
        $communicationsRepo->add([
            "id_order" => $orderId,
            "description" => $description,
            "photo_path" => $photoPath,
            "created_by" => $user->getId()
        ]);
        
        MessageUtil::setMessage("Communication added successfully");
        LocationUtils::reload();
        return;
    }
    
    if ($formType === "delete_communication") {
        $commId = (int)($_POST["comm_id"] ?? 0);
        
        if ($commId > 0) {
            $comm = $communicationsRepo->getOne(["id" => $commId]);
            
            if ($comm && $comm->id_order == $orderId) {
                // Eliminar foto si existe
                if ($comm->photo_path && file_exists($comm->photo_path)) {
                    FileUtils::removeFile($comm->photo_path);
                }
                
                $communicationsRepo->delete(["id" => $commId]);
                MessageUtil::setMessage("Communication deleted successfully");
            } else {
                MessageUtil::setMessage("Communication not found", "Error", "error");
            }
        }
        
        LocationUtils::reload();
        return;
    }
    
    if ($formType === "generate_communications_pdf") {
        try {
            $pdfPath = ClosureCommunicationsPdfGenerator::generateAndSave($orderId);
            // Guardar la URL del PDF en la sesión para que JavaScript pueda accederla
            $_SESSION['generated_pdf_url'] = $pdfPath;
            MessageUtil::setMessage("PDF generated successfully");
            LocationUtils::reload();
            return;
        } catch (\Exception $e) {
            MessageUtil::setMessage("Error generating PDF: " . $e->getMessage(), "Error", "error");
            LocationUtils::reload();
            return;
        }
    }

    LocationUtils::reload();
});

$router->run();
