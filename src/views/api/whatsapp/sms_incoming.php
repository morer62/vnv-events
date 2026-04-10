<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Repositories\WhatsappRepository;
use App\Repositories\WhatsappAccountRepository;

Cors::handle();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$router = new Router();

$router->post(function () {
    $fromPhone = str_replace('+', '', $_POST['From'] ?? '');
    $toPhone = str_replace('+', '', $_POST['To'] ?? '');
    $body = $_POST['Body'] ?? '';

    if (empty($fromPhone) || $body === '') {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Missing required fields.',
            'debug' => $_POST
        ]);
    }

    // 🔍 Buscar la cuenta usando el número receptor (To)
    $accountRepo = new WhatsappAccountRepository();
    $account = $accountRepo->getOne(["phone" => $toPhone]);

    if (!$account) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'No account found for this number: ' . $toPhone
        ]);
    }

    $repo = new WhatsappRepository(); // puedes luego renombrarlo a MessageRepository si lo usas para ambos canales

    // Buscar o crear cliente
    $client = $repo->findClientByPhone($fromPhone);
    $client_id = $client ? $client->id : $repo->createClient($fromPhone, $account->id);

    // Guardar mensaje SMS con canal explícito
    $repo->storeMessageWithChannel($client_id, $fromPhone, $toPhone, $body, 'inbound', 'sms');

    return JsonResponse::createResponse([
        'success' => true,
        'message' => 'SMS message saved in DB.'
    ]);
});

$router->run();
