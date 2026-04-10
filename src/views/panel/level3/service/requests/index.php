<?php

use App\Repositories\ClientsRequestRepository;
use App\Repositories\ServiceRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    // 1. Verificar ID del servicio
    $serviceId = $_GET['id'] ?? null;
    if (!is_numeric($serviceId)) {
        MessageUtil::setMessage("ID de servicio inválido", 'error');
        LocationUtils::redirectTo($_ENV['APP_URL'].'/panel/service');
        exit;
    }

    // 2. Verificar que el servicio existe
    $serviceRepo = new ServiceRepository();
    $service = $serviceRepo->getOne(['id' => (int)$serviceId]);
    if (!$service) {
        MessageUtil::setMessage("Servicio no encontrado", 'error');
        LocationUtils::redirectTo($_ENV['APP_URL'].'/panel/service/home');
        exit;
    }

    // 3. Obtener solicitudes
    $requestRepo = new ClientsRequestRepository();
    $requests = $requestRepo->getAllBy([
        'profile_cat' => 'vendor', 
        'profile_id' => (int)$serviceId
    ]);

    // 4. Renderizar vista
    return TemplateResponse::render(__DIR__.'/index.twig', [
        'requests' => $requests,
        'service_id' => $serviceId,
        'service' => $service,
        'app_url' => $_ENV['APP_URL'] ?? 'http://localhost/vnv-venue'
    ]);
});

$router->post(function () {
    // Verificar ID del servicio
    $serviceId = $_GET['id'] ?? null;
    if (!is_numeric($serviceId)) {
        MessageUtil::setMessage("ID de servicio inválido", 'error');
        LocationUtils::redirectTo($_ENV['APP_URL'].'/panel/service');
        exit;
    }

    $requestRepo = new ClientsRequestRepository();

    // Manejar actualización de estado
    if (isset($_POST['action']) && $_POST['action'] === 'update_status' && 
        isset($_POST['request_id'], $_POST['status'])) {
        
        $requestId = $_POST['request_id'];
        $status = $_POST['status'];
        
        // Verificar que la solicitud existe y pertenece al servicio
        $request = $requestRepo->getOne([
            'id' => $requestId,
            'profile_cat' => 'vendor',
            'profile_id' => (int)$serviceId
        ]);
        
        if (!$request) {
            MessageUtil::setMessage("Solicitud no encontrada", 'error');
        } else {
            $requestRepo->update(
                ['status' => $status],
                ['id' => $requestId]
            );
            MessageUtil::setMessage("Estado actualizado correctamente", 'success');
        }
        
        LocationUtils::redirectTo($_ENV['APP_URL']."/panel/service/requests?id=$serviceId");
        exit;
    }

    // Si llegamos aquí, redirigir de vuelta
    LocationUtils::redirectTo($_ENV['APP_URL']."/panel/service/requests?id=$serviceId");
});

try {
    $router->run();
} catch (Exception $e) {
    MessageUtil::setMessage("Error: " . $e->getMessage(), 'error');
    LocationUtils::redirectTo($_ENV['APP_URL'] . "/panel/service/home");
}