<?php

use App\Repositories\ClientsRequestRepository;
use App\Repositories\VenueRepository;
use App\Repositories\ServiceRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    $clientsRepo = new ClientsRequestRepository();
    $venueRepo = new VenueRepository();
    $serviceRepo = new ServiceRepository();

    $status = $_GET["status"] ?? "PENDING";
    $allowedStatuses = ['PENDING', 'CONTACTED', 'COMPLETED', 'CANCELLED'];

    if (!in_array($status, $allowedStatuses)) {
        MessageUtil::setMessage("STATUS NOT FOUND");
        LocationUtils::reload();
    }

    $userVenues = $venueRepo->getAllBy(["user_id" => $user->getId()]);
    $venueIds = array_map(function($venue) { return $venue->id; }, $userVenues);

    if (empty($venueIds)) {
        $requests = [];
    } else {
        $allRequests = $clientsRepo->getAllBy(["status" => $status]);
        $requests = array_filter($allRequests, function($request) use ($venueIds) {
            return $request->profile_cat === 'venue' && in_array($request->profile_id, $venueIds);
        });
    }

    foreach ($requests as &$request) {
        if ($request->profile_cat === 'venue') {
            $profile = $venueRepo->getOne(["id" => $request->profile_id]);
            $request->profile_name = $profile ? $profile->name : 'Unknown Venue';
            $request->profile_address = $profile ? $profile->address : 'N/A';
        } elseif ($request->profile_cat === 'service') {
            $profile = $serviceRepo->getOne(["id" => $request->profile_id]);
            $request->profile_name = $profile ? $profile->name : 'Unknown Service';
            $request->profile_address = $profile ? $profile->address : 'N/A';
        } else {
            $request->profile_name = 'Unknown Profile';
            $request->profile_address = 'N/A';
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "requests" => $requests,
        "statuses" => $allowedStatuses,
        "statusPage" => $status,
        "app_url" => $_ENV["APP_URL"]
    ]);
});

$router->post(function () {
    $id = $_POST["id"] ?? null;
    $status = $_POST["status"] ?? null;
    $notes = $_POST["notes"] ?? '';

    if (!$id) {
        MessageUtil::setMessage("Request ID is required.");
        LocationUtils::redirectTo($_SERVER['HTTP_REFERER'] ?? "/panel/venues/requests");
        return;
    }

    $clientsRepo = new ClientsRequestRepository();
    $request = $clientsRepo->getOne(["id" => $id]);

    if (!$request) {
        MessageUtil::setMessage("Request not found.");
        LocationUtils::redirectTo($_ENV["APP_URL"] . "/panel/venues/requests");
        return;
    }

    if ($status) {
        $updateData = ["status" => $status];

        if (!empty($notes)) {
            $updateData["notes"] = $notes;
        }

        $clientsRepo->update($updateData, ["id" => $id]);

        MessageUtil::setMessage("Request status updated to " . $status);
    }

    LocationUtils::redirectTo($_ENV["APP_URL"] . "/panel/venues/requests?status=" . $status);
});

try {
    $router->run();
} catch (Exception $e) {
    MessageUtil::setMessage("Error: " . $e->getMessage());
    LocationUtils::redirectTo($_ENV["APP_URL"] . "/panel/venues/requests");
}