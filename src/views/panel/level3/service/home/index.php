<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\ClientsRequestRepository;


$router = new Router();

$router->get(function () {
    $ServiceCategoryRepository = new ServiceCategoriesRepository();
    $ServiceRepository = new ServiceRepository();
    $user = LoginService::getSession();

    $categories = $ServiceCategoryRepository->getAll();
    $services = $ServiceRepository->getAllWithPaymentStatusByUser($user->getId());
    $requestRepo = new ClientsRequestRepository();

    foreach ($services as $service) {
        $service->request_count = count($requestRepo->getAllBy([
            "profile_cat" => "vendor",
            "profile_id" => $service->id
        ]));
    }


    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categories,
        "services" => $services,
        "canCreateService" => count($services) === 0,
        "hasServiceDiscount" => $user->hasActivePaidMembership(),
        "SERVICE_PAYMENT_AMOUNT_PER_ZIP" => $_ENV["SERVICE_PAYMENT_AMOUNT"]
    ]);
});

$router->post(function () {
    $id = $_POST['id'];
    $repo = new ServiceRepository();

    $service = $repo->getOne([
        "id" => $id
    ]);

    if (is_null($service)) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal('panel/service/home');
    }

    $repo->delete([
        "id" => $id
    ]);
    MessageUtil::setMessage("Service deleted");
    LocationUtils::redirectInternal('panel/service/home');
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
