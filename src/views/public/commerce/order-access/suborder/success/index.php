<?php

use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;

$router = new \App\Utils\Router();

$router->get(function () {
    $token = $_GET["token"] ?? null;
    if (!$token)
        LocationUtils::redirectInternal("/404");

    $secret = $_ENV["VNV_SECRET_KEY"] ?? "mySuperSecretKey";
    $decoded = json_decode(base64_decode($token), true);

    if (!$decoded || !isset($decoded["suborder_id"], $decoded["user_id"], $decoded["exp"], $decoded["hash"])) {
        LocationUtils::redirectInternal("/404");
    }

    $hashCheck = hash_hmac("sha256", json_encode([
        "suborder_id" => $decoded["suborder_id"],
        "user_id" => $decoded["user_id"],
        "exp" => $decoded["exp"]
    ]), $secret);

    if (!hash_equals((string)$decoded["hash"], $hashCheck) || time() > $decoded["exp"]) {
        LocationUtils::redirectInternal("/404");
    }

    $suborderRepo = new OrdersSuborderRepository();
    $orderRepo = new OrdersRepository();

    $suborder = $suborderRepo->getOne(["id" => $decoded["suborder_id"]]);
    if (!$suborder)
        LocationUtils::redirectInternal("/404");

    $parentOrder = $orderRepo->getOne(["id" => $suborder->id_order]);
    if (!$parentOrder)
        LocationUtils::redirectInternal("/404");

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "suborder" => $suborder,
        "parentOrder" => $parentOrder,
        "token" => $token
    ]);
});

$router->run();
