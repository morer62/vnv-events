<?php

use App\Repositories\UserRepository;
use App\Repositories\OrdersRepository;
use App\Services\GoogleCalendarService;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    try {
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;

        if (!$code || !$state) {
            echo '<script>alert("Missing authorization code or state"); window.close();</script>';
            return;
        }

        $stateData = json_decode(base64_decode($state), true);
        $clientId = $stateData['client_id'] ?? null;
        $orderId = $stateData['order_id'] ?? null;

        if (!$clientId || !$orderId) {
            echo '<script>alert("Invalid state data"); window.close();</script>';
            return;
        }

        $userRepo = new UserRepository();
        $orderRepo = new OrdersRepository();

        $client = $userRepo->getOne(['id' => $clientId]);
        $order = $orderRepo->getOne(['id' => $orderId]);

        if (!$client || !$order) {
            echo '<script>alert("Client or order not found"); window.close();</script>';
            return;
        }

        $googleClient = new Google\Client();
        $googleClient->setClientId($_ENV['GOOGLE_CLIENT_ID']);
        $googleClient->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
        $googleClient->setRedirectUri($_ENV['APP_URL'] . "/api/calendar/auth-callback");

        $token = $googleClient->fetchAccessTokenWithAuthCode($code);
        
        if (isset($token['error'])) {
            echo '<script>alert("Authentication failed: ' . $token['error'] . '"); window.close();</script>';
            return;
        }

        $encodedToken = json_encode($token);
        $userRepo->update([
            'google_token' => $encodedToken
        ], ['id' => $clientId]);

        if (is_object($client)) {
            $client->google_token = $encodedToken;
        }

        $calendarService = new GoogleCalendarService();
        $eventId = $calendarService->addEventToClientCalendar($client, $order);

        if ($eventId) {
            echo '<script>alert("Event added to your Google Calendar!"); window.close();</script>';
        } else {
            echo '<script>alert("Failed to add event to calendar"); window.close();</script>';
        }

    } catch (Exception $e) {
        echo '<script>alert("Error: ' . $e->getMessage() . '"); window.close();</script>';
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo '<script>alert("Router error: ' . $e->getMessage() . '"); window.close();</script>';
}
