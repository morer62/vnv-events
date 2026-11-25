<?php

use App\Services\BreakClubPanel;
use App\Services\BreakClubService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$router->get(static function () {
    $user = BreakClubPanel::requireUser(5);
    $service = new BreakClubService();
    $member = $service->memberForUser($user);
    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'member' => $member,
        'events' => $member ? $service->memberEvents((int)$member['id']) : [],
        'token' => BreakClubPanel::token($user),
        'notice' => BreakClubPanel::notice(),
    ]);
});
$router->post(static function () {
    $user = BreakClubPanel::requireUser(5);
    BreakClubPanel::requireToken($user, $_POST['csrf_token'] ?? null);
    $service = new BreakClubService();
    try {
        if (($_POST['action'] ?? '') === 'claim') {
            $service->claimForUser($user);
            BreakClubPanel::flash('If your account email is on the priority list, we have sent a link to connect it.');
        } elseif (($_POST['action'] ?? '') === 'unsubscribe') {
            $member = $service->memberForUser($user);
            if ($member) {
                $service->unsubscribeMember((int)$member['id']);
            }
            BreakClubPanel::flash('You have unsubscribed from club invitation emails.');
        } elseif (($_POST['action'] ?? '') === 'checkout') {
            $member = $service->memberForUser($user);
            if (!$member) {
                throw new DomainException('Link your membership before purchasing tickets.');
            }
            LocationUtils::redirectTo($service->checkout((int)($_POST['event_id'] ?? 0), (int)$member['id']));
        } else {
            throw new DomainException('Unknown action.');
        }
    } catch (DomainException | InvalidArgumentException $e) {
        BreakClubPanel::flash($e->getMessage(), 'danger');
    } catch (Throwable $e) {
        error_log('[Break Club member] ' . get_class($e) . ': ' . $e->getMessage());
        BreakClubPanel::flash('We could not complete your request. Check your membership for the latest status and try again.', 'danger');
    }
    LocationUtils::redirectInternal(BreakClubPanel::MEMBER);
});
$router->run();
