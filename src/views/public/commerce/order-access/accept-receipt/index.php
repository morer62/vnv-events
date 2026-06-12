<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Repositories\NotificationsRepository;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\Response;
use App\Services\OrderAcceptancePdfGenerator;
use App\Services\TranslationService;

$router = new \App\Utils\Router();

$router->get(function () {
    $token = $_GET["token"] ?? null;
    if ($token) {
        LocationUtils::redirectInternal("order-access?token=" . urlencode($token));
    }
    LocationUtils::redirectInternal("/404");
});

$router->post(function () {
    $token = $_POST["token"] ?? null;
    if (!$token) {
        return Response::createResponse("Token missing");
    }

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

    if (!$decoded || !isset($decoded["order_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        return Response::createResponse("Invalid token");
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "order_id" => $decoded["order_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        return Response::createResponse("Token expired or invalid");
    }

    $orderId = (int) $decoded["order_id"];
    $userId = (int) $decoded["user_id"];

    $orderRepo = new OrdersRepository();
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
    $notificationsRepo = new NotificationsRepository();

    $order = $orderRepo->getByIdWithoutOwnershipCheck($orderId);
    if ($order) {
        $order = (object) $order;
    }
    if (!$order) {
        return Response::createResponse("Order not found");
    }

    if ($acceptanceRepo->getByOrder($orderId)) {
        return Response::createResponse("This receipt acceptance has already been signed.");
    }

    $userLocalTimestamp = $_POST["user_local_timestamp"] ?? null;

    $hasSignature = !empty($_FILES["signature_image"]["tmp_name"]) || !empty(trim($_POST["typed_initials"] ?? ""));
    if (!$hasSignature) {
        return Response::createResponse("No signature provided");
    }

    $signatureImagePath = null;
    if (!empty($_FILES["signature_image"]["tmp_name"])) {
        try {
            $signatureImagePath = FileUtils::saveFile($_FILES["signature_image"], "files/signatures/");
        } catch (\Exception $e) {
            error_log("Error saving signature image: " . $e->getMessage());
        }
    }

    try {
        $result = OrderAcceptancePdfGenerator::generateAndSave($orderId, $userLocalTimestamp, $signatureImagePath);
        $filename = $result['file_path'];
        $contentHash = $result['hash'];
    } catch (\Throwable $e) {
        error_log("OrderAcceptancePdfGenerator error: " . $e->getMessage());
        return Response::createResponse("Error generating document. Please try again.");
    }

    $generatedAt = $userLocalTimestamp ?: date("Y-m-d H:i:s");
    $signatureMethod = !empty($_FILES["signature_image"]["tmp_name"]) ? "upload" : "initials";

    $acceptanceRepo->add([
        "id_order" => $orderId,
        "id_user" => $userId,
        "file_path" => $filename,
        "hash" => $contentHash,
        "ip" => $_SERVER["REMOTE_ADDR"] ?? "",
        "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? "",
        "signature_method" => $signatureMethod,
        "signature_image_path" => $signatureImagePath,
        "user_local_timestamp" => $userLocalTimestamp ? date("Y-m-d H:i:s", strtotime($userLocalTimestamp)) : null,
        "generated_at" => $generatedAt
    ]);

    $publicOrderUrl = ($_ENV["APP_URL"] ?? "vnv-venue") . "/order-access?token=" . $token;

    $notificationsRepo->add([
        "id_user" => $order->id_owner,
        "mensaje" => "📋 " . TranslationService::trans('planner_hub.order_acceptance_signed') . " - " . TranslationService::trans('planner_hub.signed_receipt_confirmation') . " #VNV341" . $order->id,
        "link" => $publicOrderUrl,
        "leido" => 0
    ]);

    $notificationsRepo->add([
        "id_user" => $userId,
        "mensaje" => "✅ " . TranslationService::trans('planner_hub.order_acceptance_signed') . " - " . TranslationService::trans('planner_hub.signed_receipt_confirmation') . " #VNV341" . $order->id,
        "link" => $publicOrderUrl,
        "leido" => 0
    ]);

    $redirectUrl = "order-access?token=" . urlencode($token) . "&t=" . time();
    LocationUtils::redirectInternal($redirectUrl);
});

$router->run();
