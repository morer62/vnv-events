<?php

use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Repositories\WhatsappRepository;
use App\Repositories\WhatsappAccountRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationService;

Cors::handle();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$router = new Router();

$router->post(function () {
    $fromPhone = str_replace(['whatsapp:', '+'], '', $_POST['From'] ?? '');
    $toPhone = str_replace(['whatsapp:', '+'], '', $_POST['To'] ?? '');
    $body = $_POST['Body'] ?? '';
    $numMedia = (int) ($_POST['NumMedia'] ?? 0);

    if (empty($fromPhone) || ($body === '' && $numMedia === 0)) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'Missing required fields.',
            'debug' => $_POST
        ]);
    }

    $accountRepo = new WhatsappAccountRepository(); 
    $account = $accountRepo->getOne(["phone" => $toPhone]);
    if (!$account) {
        return JsonResponse::createResponse([
            'success' => false,
            'message' => 'No WhatsApp account found for ' . $toPhone
        ]);
    }

    $repo = new WhatsappRepository();

    $client = $repo->findClientByPhone($fromPhone);
    
    if (!$client) {
        $client_id = $repo->createClient($fromPhone, $account->id);
    } else {
        $client_id = $client->id;
    }

    $messageId = $repo->storeMessage($client_id, $fromPhone, $toPhone, $body, 'inbound');

    if ($numMedia > 0) {
        for ($i = 0; $i < $numMedia; $i++) {
            $mediaUrl = $_POST["MediaUrl{$i}"] ?? '';
            $mediaType = $_POST["MediaContentType{$i}"] ?? '';
            if ($mediaUrl && $mediaType) {
                $repo->storeMedia($messageId, $mediaUrl, $mediaType);
            }
        }
    }

    $userRepo = new UserRepository();
    $user = $userRepo->getOneWithoutOwnership(['id' => 2]);
    $expo_token = $user->expo_token ?? null;

    if ($expo_token) {
        NotificationService::sendExpoNotification(
            $expo_token,
            '📩 New WhatsApp Message',
            $body ?: 'You received a media file'
        );
    }

    return JsonResponse::createResponse([
        'success' => true,
        'message' => 'Message saved'
    ]);
});

$router->run();
