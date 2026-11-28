<?php

use App\Services\VnvProductionCatalog;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    if (empty($_SESSION['quick_request_csrf'])) {
        $_SESSION['quick_request_csrf'] = bin2hex(random_bytes(24));
    }
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'production_services' => VnvProductionCatalog::services(),
        'fee_includes' => VnvProductionCatalog::FEE_INCLUDES,
        'overtime' => VnvProductionCatalog::OVERTIME,
        'direct_costs' => VnvProductionCatalog::DIRECT_COSTS,
        'production_phases' => VnvProductionCatalog::phases(),
        'quick_request_csrf' => $_SESSION['quick_request_csrf'],
        'quick_submitted' => isset($_GET['quick_submitted']),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
