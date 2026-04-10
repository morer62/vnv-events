<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Repositories\WhatsappRepository;
use App\Repositories\WhatsappAccountRepository;
use Twilio\Rest\Client;

Cors::handle();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$router = new Router();

$router->post(function () {
    $client_id = $_POST["client_id"] ?? null;
    $manualPhone = trim($_POST["phone"] ?? '');
    $body = trim($_POST["message"] ?? "");

    if (!$client_id && !$manualPhone) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "client_id or phone is required"
        ]);
    }

    if ($body === "") {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Message is required"
        ]);
    }

    $repo = new WhatsappRepository(); // usa este mismo repo por ahora
    $clientPhone = '';
    $accountRepo = new WhatsappAccountRepository();
    $account = $accountRepo->getActive();

    if (!$account) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "No active SMS account"
        ]);
    }

    $clientFrom = $account->phone;
    $sid = $account->account_sid;
    $token = $account->auth_token;

    if ($client_id) {
        $client = $repo->findClientById((int)$client_id);
        if (!$client) {
            return JsonResponse::createResponse([
                "success" => false,
                "message" => "Client not found"
            ]);
        }
        $clientPhone = $client->phone;
    } else {
        $cleanPhone = preg_replace('/[^0-9]/', '', $manualPhone);
        $clientPhone = $cleanPhone;

        $existing = $repo->findClientByPhone($clientPhone);
        if ($existing) {
            $client_id = $existing->id;
        } else {
            $client_id = $repo->createClient($clientPhone, $account->id);
        }
    }

    $twilio = new Client($sid, $token);

    try {
        $response = $twilio->messages->create("+{$clientPhone}", [
            "from" => "+{$clientFrom}",
            "body" => $body
        ]);

        $repo->storeMessageWithChannel($client_id, $clientFrom, $clientPhone, $body, 'outbound', 'sms');

        return JsonResponse::createResponse([
            "success" => true,
            "message" => "SMS sent successfully"
        ]);
    } catch (Exception $e) {
        return JsonResponse::createResponse([
            "success" => false,
            "message" => "Twilio error: " . $e->getMessage()
        ]);
    }
});

$router->run();
