<?php

use App\Services\TicketSalesService;
use App\Utils\Cors;
use App\Utils\JsonResponse;

Cors::handle();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return JsonResponse::createResponse([
        'success' => false,
        'message' => 'Method not allowed'
    ], 405);
}

try {
    // Verificar autenticación
    $user = \App\Services\LoginService::getSession();
    if (!$user) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Authentication required'
        ], 401);
    }

    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Invalid JSON data'
        ], 400);
    }

    $paymentIntentId = $input['payment_intent_id'] ?? null;
    $buyerInfo = $input['buyer_info'] ?? [];

    if (!$paymentIntentId || empty($buyerInfo['name']) || empty($buyerInfo['email'])) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Payment intent ID and buyer information are required'
        ], 400);
    }

    // Inicializar servicio
    $ticketSalesService = new TicketSalesService();
    
    // Confirmar la compra
    $result = $ticketSalesService->confirmTicketPurchase($paymentIntentId, $buyerInfo);

    if ($result['success']) {
        return JsonResponse::createResponse([
            'success' => true,
            'message' => 'Tickets purchased successfully!',
            'data' => $result['data']
        ]);
    } else {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => $result['message']
        ], 400);
    }

} catch (Exception $e) {
    error_log("Ticket confirmation error: " . $e->getMessage());
    return JsonResponse::createResponse([
        'success' => false,
        'message' => 'An error occurred while confirming your purchase'
    ], 500);
}
