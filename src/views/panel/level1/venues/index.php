<?php

use App\Repositories\VenueCategoriesRepository;
use App\Repositories\VenueRepository;
use App\Repositories\UserCardsRepository;
use App\Repositories\PaymentsVenuesRepository;
use App\Services\LoginService;
use App\Services\StripeService;
use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\NotificationService;

$router = new Router();

$router->get(function () {
    $venueCategoryRepository = new VenueCategoriesRepository();
    $venueRepository = new VenueRepository();
    $status = $_GET["status"] ?? "PENDING";

    if (!in_array($status, VenueRepository::STATUSES)) {
        MessageUtil::setMessage("STATUS NOT FOUND");
        LocationUtils::reload();
    }

    $categories = $venueCategoryRepository->getAll();

    $venues = $venueRepository->getAllBy([
        "status" => $status
    ]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "categories" => $categories,
        "venues" => $venues,
        "statuses" => VenueRepository::STATUSES,
        "statusPage" => $status
    ]);
});

$router->post(function () {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $venueRepo = new VenueRepository();
    $venue = $venueRepo->getOne(["id" => $id]);
    
    if (isset($_POST["wordpress_profile"])) {
        $venueRepo->update([
            "wordpress_profile" => $_POST["wordpress_profile"]
        ], ["id" => $id]);

        MessageUtil::setMessage("WordPress profile link updated.");
        LocationUtils::redirectInternal("panel/venues?status=" . $venue->status);
    }

    if (!$venue) {
        MessageUtil::setMessage("Venue not found");
        LocationUtils::redirectInternal('panel/venues');
    }

    // Si el nuevo estado es REJECTED, simplemente lo marcamos así
    if ($status === "REJECTED") {
        $venueRepo->update(["status" => "REJECTED"], ["id" => $id]);
        MessageUtil::setMessage("Venue rejected");

        // 🔔 Notificar rechazo
        NotificationService::sendToUsers(
            [$venue->user_id],
            '❌ Venue Rejected',
            'Your venue was rejected. Please log in to your account to update the information.'
        );
        LocationUtils::redirectInternal('panel/venues?status=REJECTED');
    }

    // Si el nuevo estado es APPROVED, validamos tarjeta y procesamos pago
    if ($status === "APPROVED") {
        $cardRepo = new UserCardsRepository();
        $card = $cardRepo->getOne([
            "id_user" => $venue->user_id,
            "main_card" => 'yes'
        ]);

        if (is_null($card)) {
            MessageUtil::setMessage("User has no payment method. Venue rejected.");
            $venueRepo->update(["status" => "REJECTED"], ["id" => $id]);
            LocationUtils::redirectInternal("panel/venues?status=REJECTED");
        }

        $stripe = new StripeService(); 
        // Verificar si el usuario tiene descuento por membresía
        $userRepo = new \App\Repositories\UserRepository();
        $user = $userRepo->getOne(["id" => $venue->user_id]);

        $amount = $user->membership_type === 'PAID'
            ? floatval($_ENV["VENUE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"])
            : floatval($_ENV["VENUE_PAYMENT_AMOUNT"]);

        $success = $stripe->createChargeV1($card->token, $amount);

        if (!$success) {
            MessageUtil::setMessage("Payment failed. Venue rejected.");
            $venueRepo->update(["status" => "REJECTED"], ["id" => $id]);
            LocationUtils::redirectInternal("panel/venues?status=REJECTED");
        }

        // Si el pago fue exitoso, actualizamos el estado y registramos el pago
        $paymentRepo = new PaymentsVenuesRepository(); 

        // CORREGIDO: Usar la variable correcta para venue renewal
        $renewal = date("Y-m-d", strtotime("+".$_ENV["FREE_DAYS_BEFORE_RENEWAL_PLANNER_HUB"]." days"));

        $paymentRepo->add([
            "id_venues" => $venue->id,
            "payment_date" => date("Y-m-d"),
            "renewal" => $renewal,
            "active" => "yes"
        ]);
 
        $venueRepo->registerVenuePaymentToAll($venue, $amount);
         
        $venueRepo->update(["status" => "APPROVED", "expiration_date"=> $renewal ], ["id" => $id]);

        // 🔔 Notificar aprobación
        NotificationService::sendToUsers(
            [$venue->user_id],
            '🎉 Venue Approved',
            'Your venue has been approved! You can now start receiving leads.'
        );
        MessageUtil::setMessage("Venue approved and payment successful.");

        $extraDays = intval($_ENV["FREE_DAYS_BEFORE_RENEWAL_PLANNER_HUB"]);
        $currentDue = $user->membership_due_date ? new DateTime($user->membership_due_date) : new DateTime();

        $newDueDate = (clone $currentDue)->modify("+{$extraDays} days")->format("Y-m-d");

        $userRepo->updateData($user->id, [
            'membership_due_date' => $newDueDate,
            'membership_type' => 'PAID'
        ]);
        LocationUtils::redirectInternal("panel/venues?status=APPROVED");
    }

    $venueRepo->update(["status" => $status], ["id" => $id]);

    // Default redirect if no conditions matched
    LocationUtils::redirectInternal("panel/venues");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}