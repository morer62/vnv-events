<?php

use App\Repositories\MobileAppBroadcastsRepository;
use App\Repositories\NotificationsRepository;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\NotificationService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->post(function () {
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'send_mobile_broadcast') {
        LocationUtils::redirectInternal('panel/cms/mobile-push');
    }

    $title = trim((string)($_POST['mobile_title'] ?? ''));
    $body = trim((string)($_POST['mobile_body'] ?? ''));
    $link = trim((string)($_POST['mobile_link'] ?? ''));
    $link = $link !== '' ? $link : null;

    if ($title === '' || $body === '') {
        MessageUtil::setMessage('Title and message are required for mobile app notifications.', 'Missing fields', 'warning');
        LocationUtils::redirectInternal('panel/cms/mobile-push');
    }

    if (strlen($title) > 140) {
        $title = substr($title, 0, 140);
    }

    if (strlen($body) > 800) {
        $body = substr($body, 0, 800);
    }

    $usersRepo = new UserRepository();
    $notificationsRepo = new NotificationsRepository();
    $broadcastsRepo = new MobileAppBroadcastsRepository();
    $sender = LoginService::getSession();

    $recipients = $usersRepo->getMobileAppNotificationRecipients();
    if (count($recipients) === 0) {
        MessageUtil::setMessage('No active mobile app users with push tokens were found.', 'No recipients', 'warning');
        LocationUtils::redirectInternal('panel/cms/mobile-push');
    }

    $broadcastId = $broadcastsRepo->createBroadcast($sender ? (int)$sender->getId() : null, $title, $body, $link);
    $notificationCount = 0;
    $pushSent = 0;
    $pushFailed = 0;

    foreach ($recipients as $recipient) {
        $message = $title . "\n" . $body;
        $notificationLink = 'mobile-app-broadcast://' . $broadcastId . ($link ? ('?link=' . rawurlencode($link)) : '');
        $notificationsRepo->add([
            'id_user' => (int)$recipient->id,
            'mensaje' => $message,
            'link' => $notificationLink,
            'leido' => 0,
        ]);

        $notificationId = (int)$notificationsRepo->db->lastId();
        if ($notificationId > 0) {
            $notificationCount++;
        }

        $result = NotificationService::sendExpoNotificationWithResult(
            (string)$recipient->expo_token,
            $title,
            $body,
            [
                'type' => 'mobile_app_broadcast',
                'broadcast_id' => $broadcastId,
                'notification_id' => $notificationId,
                'link' => $link,
                'screen' => 'NotificationDetail',
            ]
        );

        if ($result['ok'] ?? false) {
            $pushSent++;
        } else {
            $pushFailed++;
            if (($result['expo_error'] ?? null) === 'DeviceNotRegistered') {
                $usersRepo->clearExpoToken((int)$recipient->id);
            }
        }
    }

    $broadcastsRepo->updateStats($broadcastId, count($recipients), $pushSent, $pushFailed, $notificationCount);

    MessageUtil::setMessage("Mobile app broadcast sent to {$pushSent} device(s). {$notificationCount} notification record(s) created.");
    LocationUtils::redirectInternal('panel/cms/mobile-push');
});

$router->get(function () {
    $broadcastsRepository = new MobileAppBroadcastsRepository();
    $usersRepository = new UserRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Mobile Push",
        "mobilePushRecipientsCount" => count($usersRepository->getMobileAppNotificationRecipients()),
        "recentMobileBroadcasts" => $broadcastsRepository->getRecent(20),
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
