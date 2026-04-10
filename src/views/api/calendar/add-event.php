<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';

use App\Repositories\UserRepository;
use App\Repositories\OrdersRepository;
use App\Services\GoogleCalendarService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true, 'message' => 'CORS OK']);
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }

    $orderId = $input['order_id'] ?? null;
    $clientId = $input['client_id'] ?? null;

    if (!$orderId || !$clientId) {
        echo json_encode(['success' => false, 'message' => 'Missing order_id or client_id']);
        exit;
    }

    $userRepo = new UserRepository();
    $orderRepo = new OrdersRepository();

    // Get client info
    $client = $userRepo->getOne(['id' => $clientId]);
    if (!$client) {
        echo json_encode(['success' => false, 'message' => 'Client not found']);
        exit;
    }

    // Get order details  
    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if ($order) {
        $order = (object)$order;
    }
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // Check if client has Google token
    if (empty($client->google_token)) {
        // Client needs to authenticate with Google
        $googleClient = new Google\Client();
        $googleClient->setClientId($_ENV['GOOGLE_CLIENT_ID']);
        $googleClient->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
        $googleClient->setRedirectUri($_ENV['APP_URL'] . "/api/calendar/auth-callback");
        $googleClient->addScope(Google\Service\Calendar::CALENDAR_EVENTS);
        $googleClient->setAccessType('offline');
        $googleClient->setPrompt('consent');

        // Set state before generating the auth URL, so Google includes it
        $state = base64_encode(json_encode(['client_id' => $clientId, 'order_id' => $orderId]));
        if (method_exists($googleClient, 'setState')) {
            $googleClient->setState($state);
        }
        $authUrl = $googleClient->createAuthUrl();

        echo json_encode([
            'success' => false,
            'needs_auth' => true,
            'auth_url' => $authUrl,
            'message' => 'Client needs to authorize Google Calendar access'
        ]);
        exit;
    }

    // Client has token, try to add event
    $calendarService = new GoogleCalendarService();
    $eventId = $calendarService->addEventToClientCalendar($client, $order);

    if ($eventId) {
        echo json_encode([
            'success' => true,
            'event_id' => $eventId,
            'message' => 'Event added to client\'s Google Calendar'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to add event to calendar'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Exception: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}