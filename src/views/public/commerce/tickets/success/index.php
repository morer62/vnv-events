<?php

use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

// Verificar autenticación
$user = \App\Services\LoginService::getSession();
if (!$user) {
    LocationUtils::redirectInternal("login");
}

// Obtener datos de la sesión
$ticketCodes = $_SESSION['ticket_codes'] ?? [];
$totalAmount = $_SESSION['ticket_total'] ?? 0;
$eventName = $_SESSION['event_name'] ?? 'Event';
$currentStage = $_SESSION['current_stage'] ?? null;

// Verificar que tenemos datos
if (empty($ticketCodes)) {
    LocationUtils::redirectInternal("");
    return;
}

if ($totalAmount <= 0) {
    LocationUtils::redirectInternal("");
    return;
}

if (empty($eventName)) {
    LocationUtils::redirectInternal("");
    return;
}

// Verificar que el template existe
$templatePath = __DIR__ . "/index.twig";
if (!file_exists($templatePath)) {
    echo "Error: Template file not found";
    exit;
}

// Renderizar template Twig
try {
    $result = TemplateResponse::render(__DIR__ . "/index.twig", [
        "ticket_codes" => $ticketCodes,
        "total_amount" => $totalAmount,
        "event_name" => $eventName,
        "current_stage" => $currentStage,
        "user" => $user,
        "base_url" => $_ENV["APP_URL"]
    ]);
    
    // Limpiar datos de sesión
    unset($_SESSION['ticket_codes']);
    unset($_SESSION['ticket_total']);
    unset($_SESSION['event_name']);
    unset($_SESSION['current_stage']);
    
    echo $result;
    exit;
    
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p>Error rendering success page: " . $e->getMessage() . "</p>";
    exit;
}
