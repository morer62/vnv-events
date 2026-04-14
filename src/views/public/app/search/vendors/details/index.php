<?php 

use App\Repositories\ServiceRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $id = $_GET['id'] ?? null;

    if (!$id || !is_numeric($id)) {
        return "Invalid service ID.";
    }

    $repo = new ServiceRepository();
 
    $service = $repo->getServiceWithDetailsById((int)$id);

    if (!$service || $service->status !== 'APPROVED') {
        return "Service not found or not available.";
    }

  
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'service' => $service,
        'base_url' => $_ENV["APP_URL"],
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<pre>";
    echo "<strong>Message:</strong> " . $e->getMessage() . "<br><br>";
    echo "<strong>File:</strong> " . $e->getFile() . " on line " . $e->getLine() . "<br><br>";
    echo "<strong>Stack Trace:</strong><br>" . $e->getTraceAsString();
    echo "</pre>";
}