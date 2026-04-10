<?php

use App\Repositories\NotificationsRepository;
use App\Utils\Response;

header('Content-Type: application/json');

// Debug: Log de la petición
error_log("DEBUG: mark-read.php ejecutándose");
error_log("DEBUG: Método HTTP: " . $_SERVER['REQUEST_METHOD']);
error_log("DEBUG: Raw input: " . file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$notificationsRepo = new NotificationsRepository();
$data = json_decode(file_get_contents('php://input'), true);

error_log("DEBUG: Datos decodificados: " . print_r($data, true));

if (isset($data['notification_id'])) {
    $notificationId = (int) $data['notification_id'];
    error_log("DEBUG: Intentando marcar como leída la notificación ID: " . $notificationId);
    
    $success = $notificationsRepo->markAsRead($notificationId);
    
    error_log("DEBUG: Resultado de markAsRead: " . ($success ? 'TRUE' : 'FALSE'));
    
    echo json_encode([
        'success' => $success,
        'notification_id' => $notificationId
    ]);
} else {
    error_log("DEBUG: Error - notification_id no encontrado en los datos");
    echo json_encode([
        'success' => false,
        'error' => 'Notification ID required'
    ]);
}
