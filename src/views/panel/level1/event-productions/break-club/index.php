<?php

use App\Services\BreakClubPanel;
use App\Services\BreakClubService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();
$router->get(static function () {
    $user = BreakClubPanel::requireUser(1);
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 150) : '';
    $eventId = max(0, (int)($_GET['event'] ?? 0));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $data = (new BreakClubService())->adminData($search, $eventId, $page);
    $selected = null;
    foreach ($data['events'] as $event) {
        if ((int)$event['id'] === $eventId) {
            $selected = $event;
            break;
        }
    }
    $invitedMembers = [];
    foreach ($data['registrations'] as $registration) {
        if (!empty($registration['invited_at'])) {
            $invitedMembers[(int)$registration['member_id']] = true;
        }
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    return TemplateResponse::render(__DIR__ . '/index.twig', $data + [
        'search' => $search,
        'selected' => $selected,
        'upcoming' => $selected && $selected['starts_at'] > $now->format('Y-m-d H:i:s'),
        'invited_members' => $invitedMembers,
        'categories' => BreakClubService::CATEGORIES,
        'token' => BreakClubPanel::token($user),
        'notice' => BreakClubPanel::notice(),
    ]);
});
$router->post(static function () {
    $user = BreakClubPanel::requireUser(1);
    BreakClubPanel::requireToken($user, $_POST['csrf_token'] ?? null);
    $service = new BreakClubService();
    $eventId = max(0, (int)($_POST['event_id'] ?? 0));
    $anchor = '';
    try {
        switch ($_POST['action'] ?? '') {
            case 'save_event':
                $eventId = $service->saveEvent($_POST);
                BreakClubPanel::flash('Event saved.');
                break;
            case 'invite':
                $anchor = '#members';
                $service->invite($eventId, (int)($_POST['member_id'] ?? 0));
                BreakClubPanel::flash('The invitation has been sent.');
                break;
            case 'checkin':
                $anchor = '#guest-list';
                $service->checkIn((int)($_POST['registration_id'] ?? 0), (string)($_POST['role'] ?? ''), (int)$user->getId());
                BreakClubPanel::flash('Guest checked in.');
                break;
            case 'reconcile':
                $anchor = '#guest-list';
                $service->reconcile((int)($_POST['registration_id'] ?? 0));
                BreakClubPanel::flash('Payment status checked.');
                break;
            default:
                throw new DomainException('Unknown action.');
        }
    } catch (DomainException | InvalidArgumentException $e) {
        BreakClubPanel::flash($e->getMessage(), 'danger');
    } catch (Throwable $e) {
        error_log('[Break Club admin] ' . get_class($e) . ': ' . $e->getMessage());
        BreakClubPanel::flash('The action could not be completed. Refresh the list before trying again.', 'danger');
    }
    $query = array_filter([
        'event' => $eventId,
        'q' => is_string($_POST['q'] ?? null) ? mb_substr(trim($_POST['q']), 0, 150) : '',
        'page' => max(1, (int)($_POST['page'] ?? 1)),
    ], static fn ($value, $key) => $key === 'page' ? $value > 1 : $value !== '' && $value !== 0, ARRAY_FILTER_USE_BOTH);
    LocationUtils::redirectInternal(BreakClubPanel::ADMIN . ($query ? '?' . http_build_query($query) : '') . $anchor);
});
$router->run();
