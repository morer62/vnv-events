<?php 

use App\Repositories\ServiceRepository;
use App\Utils\ParamsUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$repo = new ServiceRepository();

$params = ParamsUtils::getRouteParams();

$id = $params[0];
$service = $repo->getServiceWithDetailsById((int)$id);

if (!$service || $service->status !== 'APPROVED') {
    echo TemplateREsponse::renderInTemplates("error.twig", [
        "message" => "Service not found or not available."
    ]);
    exit;
}


TemplateResponse::renderAndDisplay(__DIR__. "/index.twig", [
    'service' => $service
]);
