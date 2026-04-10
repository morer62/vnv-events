<?php

use App\Repositories\PaymentsVenuesRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;
use App\Repositories\VenueRepository;
use App\Services\StripeService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    try {
        $type = $_GET['type'] ?? 'services';

        if ($type === 'services') {
            $repo = new ServiceRepository();
            $expired = $repo->getExpiredWithUser();
        } else {
            $repo = new VenueRepository();
            $expired = $repo->getExpiredWithUser();
        }

        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "expired" => $expired,
            "type" => $type
        ]);
    } catch (\Exception $e) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "expired" => [],
            "type" => $type ?? 'services',
            "error" => "Error loading expired payments: " . $e->getMessage()
        ]);
    }
});

$router->post(function () {
    try {
        $type = $_POST['type'] ?? '';
        $stripe = new StripeService();
        $userRepo = new UserRepository();
        $success = 0;

        if ($type === 'services') {
            $repo = new ServiceRepository();
            $records = $repo->getExpiredWithUser();
        } elseif ($type === 'venues') {
            $repo = new VenueRepository();
            $records = $repo->getExpiredWithUser();
        } else {
            MessageUtil::setMessage("Invalid type");
            LocationUtils::redirectInternal("panel/payments-expired");
            return;
        }

        $today = date("Y-m-d");
        $newExpiration = date("Y-m-d", strtotime("+30 days"));

        foreach ($records as $rec) {
            $user = $userRepo->getOne(["id" => $rec->user_id]);

            $hasMembership = $user->membership_type === 'PAID' && $user->membership_due_date >= date("Y-m-d");

            $amount = match ($type) {
                'services' => $hasMembership
                    ? floatval($_ENV["SERVICE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"])
                    : floatval($_ENV["SERVICE_PAYMENT_AMOUNT"]),
                'venues' => $hasMembership
                    ? floatval($_ENV["VENUE_PAYMENT_AMOUNT_WITH_MEMBERSHIP_DISCOUNT"])
                    : floatval($_ENV["VENUE_PAYMENT_AMOUNT"]),
            };

            if (strpos($rec->user_token, 'cus_test_token_') === 0) {
                $charge = true;
            } else {
                $charge = $stripe->chargeUserToken($rec->user_token, $amount);
            }

            if ($charge) {
                if ($type === 'services') {
                    $serviceRepo = new ServiceRepository();
                    $serviceRepo->update([
                        "expiration_date" => $newExpiration
                    ], [
                        "id" => $rec->id_service
                    ]);
                } elseif ($type === 'venues') {
                    $venueRepo = new VenueRepository();
                    $venueRepo->update([
                        "expiration_date" => $newExpiration
                    ], [
                        "id" => $rec->id_venues
                    ]);
                }

                if ($type === 'services') {
                    $paymentRepo = new \App\Repositories\ServiceZipPaymentsRepository();
                    $paymentRepo->add([
                        "id_service" => $rec->id_service,
                        "payment_date" => $today,
                        "renewal" => $newExpiration,
                        "status" => "ACTIVE",
                        "total" => $amount
                    ]);
                } elseif ($type === 'venues') {
                    $paymentRepo = new \App\Repositories\PaymentsVenuesRepository();
                    $paymentRepo->add([
                        "id_venues" => $rec->id_venues,
                        "payment_date" => $today,
                        "renewal" => $newExpiration,
                        "active" => "yes",
                        "total" => $amount
                    ]);
                }

                $success++;
            }
        }

        MessageUtil::setMessage("Renewed {$success} {$type} successfully.");
        LocationUtils::redirectInternal("panel/payments-expired?type=" . $type);
    } catch (\Exception $e) {
        MessageUtil::setMessage("Error processing renewals: " . $e->getMessage());
        LocationUtils::redirectInternal("panel/payments-expired?type=" . $type);
    }
});

try {
    $router->run();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}