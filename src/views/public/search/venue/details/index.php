<?php

use App\Repositories\VenueRepository;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET['id'] ?? null;
    if (!$id || !is_numeric($id)) {
        return "Invalid venue ID.";
    }

    $repo = new VenueRepository();
    $venue = $repo->getFullVenueDetails((int) $id);
    // var_dump($venue); // Debugging line to check the venue details


    if (!$venue || $venue->status !== 'APPROVED') {
        return "Venue not found or not available.";
    }

    // Check if user is authenticated
    $user = LoginService::getSession();
    $userData = null;
    
    if ($user) {
        $userData = [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'lastname' => $user->getLastname(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone()
        ];
    }
 
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'venue' => $venue,
        'base_url' => $_ENV["APP_URL"],
        'user' => $userData,
        'is_authenticated' => $user !== null,
        '_ENV' => $_ENV
    ]);

});

try {
    $router->run();
} catch (Exception $e) {
    // Check if this is an AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        return \App\Utils\JsonResponse::createResponse([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    echo $e->getMessage();
}