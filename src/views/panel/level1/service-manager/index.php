<?php

use App\Repositories\ServiceCategoriesRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\PaymentsServicesRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Services\NotificationService;
use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $ServiceCategoryRepository = new ServiceCategoriesRepository();
    $ServiceRepository = new ServiceRepository();
    $status = $_GET["status"] ?? "PENDING";

    if (!in_array($status, ServiceRepository::STATUSES)) {
        MessageUtil::setMessage("STATUS NOT FOUND");
        LocationUtils::reload();
    }

    $categories = $ServiceCategoryRepository->getAll();
    $services = $ServiceRepository->getAllBy(["status" => $status]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categories,
        "services" => $services,
        "statuses" => ServiceRepository::STATUSES,
        "statusPage" => $status
    ]);
});

$router->post(function () {
    $id = $_POST['id'];
    $status = $_POST['status'] ?? null;
    $serviceRepository = new ServiceRepository();
    $service = $serviceRepository->getOne(["id" => $id]);

    if (!$service) {
        MessageUtil::setMessage("Service not found");
        LocationUtils::redirectInternal('panel/service-manager');
    }

    if (isset($_POST["wordpress_profile"])) {
        $serviceRepository->update(["wordpress_profile" => $_POST["wordpress_profile"]], ["id" => $id]);
        MessageUtil::setMessage("WordPress profile link updated."); 
        LocationUtils::redirectInternal('panel/service-manager?status=' . $service->status);
    }

    if ($status === 'APPROVED') {
    $cardsRepo = new UserCardsRepository();
    $mainCard = $cardsRepo->getOne([
        "id_user" => $service->user_id,
        "main_card" => 'yes'
    ]);

    if (!$mainCard) {
        MessageUtil::setMessage("No main card found for this user.");
        LocationUtils::redirectInternal('panel/service-manager?status=PENDING');
    }

    $userRepo = new UserRepository();
    $user = $userRepo->getOne(["id" => $service->user_id]);

    $amount = $user->membership_type === 'PAID'
        ? floatval($_ENV["SERVICE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"])
        : floatval($_ENV["SERVICE_PAYMENT_AMOUNT"]);
    
    $stripe = new StripeService();
    $charge = $stripe->createChargeV1($mainCard->token, $amount);

    if (!$charge) {
        $serviceRepository->update(["status" => "REJECTED"], ["id" => $id]);
        MessageUtil::setMessage("Payment failed. Service rejected.");
        LocationUtils::redirectInternal('panel/service-manager?status=REJECTED');
    }

    $renewal =  date("Y-m-d", strtotime("+".$_ENV["FREE_DAYS_BEFORE_RENEWAL_LISTING"]." days"));

    // 1. Registrar el pago
    $paymentRepo = new PaymentsServicesRepository();
    $paymentRepo->add([
        "id_service" => $service->id,
        "payment_date" => date("Y-m-d"),
        "renewal" => $renewal , 
        "active" => "yes"
    ]);

    try {
        $serviceRepository->registerServicePaymentToAll($service, 1, $amount);
    } catch (\Exception $e) {
        // No fallar el proceso por este error
    }
    
    // Actualizar el status DESPUÉS de registerServicePaymentToAll
    $serviceRepository->update(["status" => "APPROVED" , "expiration_date"=> $renewal], ["id" => $id]);
 

    // 3. Notificación (opcional)
    NotificationService::sendToUsers(
        [$service->user_id],
        '🎉 Service Approved',
        'Your service has been approved! You can now start receiving leads.'
    );

    $extraDays = intval($_ENV["FREE_DAYS_BEFORE_RENEWAL_PLANNER_HUB"]);
    $currentDue = $user->membership_due_date ? new DateTime($user->membership_due_date) : new DateTime();

    $newDueDate = (clone $currentDue)->modify("+{$extraDays} days")->format("Y-m-d");

    $userRepo->updateData($user->id, [
        'membership_due_date' => $newDueDate,
        'membership_type' => 'PAID'
    ]);


    MessageUtil::setMessage("Service approved, payment processed, and membership extended.");
    LocationUtils::redirectInternal('panel/service-manager?status=APPROVED');
}


    $serviceRepository->update(["status" => $status], ["id" => $id]);
    MessageUtil::setMessage("Service status updated.");
    LocationUtils::redirectInternal('panel/service-manager?status=' . $status);
});

$router->run();
