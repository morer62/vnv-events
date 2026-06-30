<?php

use App\Repositories\MusicSessionRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersAcceptanceContractsRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\TicketSalesRepository;
use App\Repositories\TeamMemberContractsRepository;
use App\Repositories\VenueEventsRepository;
use App\Repositories\UserRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function level5ActiveTeamContract(int $userId): ?object
{
    return (new TeamMemberContractsRepository())->getLatestActiveForMember($userId);
}

$router->get(function () {
    $user = LoginService::getSession();
    if (!$user || (int)$user->getLevel() !== 5) {
        LocationUtils::redirectInternal('login');
    }

    $ordersRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $paymentsRepo = new OrdersPaymentsRepository();
    $acceptanceRepo = new OrdersAcceptanceContractsRepository();
    $ticketsRepo = new TicketSalesRepository();
    $venueEventsRepo = new VenueEventsRepository();
    $sessionsRepo = new MusicSessionRepository();
    $activeTeamContract = level5ActiveTeamContract((int)$user->getId());

    $orders = $ordersRepo->getOrdersForClientWithCompany((int)$user->getId());
    $secret = $_ENV['VNV_SECRET_KEY'] ?? 'mySuperSecretKey';
    $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/vnv-events'), '/');
    $today = new DateTimeImmutable('today');

    $pendingPayments = [];
    $pendingSignatures = [];
    $upcomingEvents = [];
    $totalBalanceDue = 0.0;

    foreach ($orders as $order) {
        $orderId = (int)($order->id ?? 0);
        if ($orderId <= 0) {
            continue;
        }

        $payload = [
            'order_id' => $orderId,
            'user_id' => (int)($order->id_client ?? $user->getId()),
            'exp' => time() + (86400 * 30),
        ];
        $payload['hash'] = hash_hmac('sha256', json_encode([
            'order_id' => $payload['order_id'],
            'user_id' => $payload['user_id'],
            'exp' => $payload['exp'],
        ]), $secret);

        $order->contract_token = base64_encode(json_encode($payload));
        $order->action_url = $appUrl . '/order-access?token=' . $order->contract_token;

        $orderBalance = 0.0;
        foreach ($suborderRepo->getByOrder($orderId) as $suborder) {
            $services = $suborderServicesRepo->getServicesWithDetails((int)$suborder->id);
            $subtotal = 0.0;
            foreach ($services as $service) {
                $subtotal += ((float)($service->quantity ?? 0)) * ((float)($service->actual_price ?? 0));
            }

            $taxRate = isset($suborder->tax_percertance) ? (float)$suborder->tax_percertance : 0.0;
            $total = round($subtotal + ($subtotal * ($taxRate / 100.0)), 2);
            $paid = 0.0;

            foreach ($paymentsRepo->getAllBy(['id_order' => $orderId, 'id_suborder' => (int)$suborder->id]) as $payment) {
                $amount = isset($payment->amount) ? (float)$payment->amount : 0.0;
                $refunded = isset($payment->refunded_amount) ? (float)$payment->refunded_amount : 0.0;
                $paid += max(0.0, $amount - $refunded);
            }

            $orderBalance += max(0.0, round($total - $paid, 2));
        }

        $order->balance_due = round($orderBalance, 2);
        $status = strtoupper(trim((string)($order->status_workflow ?? '')));

        if ($status === 'INVOICE_DRAFT') {
            $acceptance = $acceptanceRepo->getByOrder($orderId);
            if (!$acceptance) {
                $pendingSignatures[] = $order;
            }
        }

        if ($orderBalance > 0.009 || in_array($status, ['INVOICE_READY', 'INVOICE_PARTIAL'], true)) {
            $pendingPayments[] = $order;
            $totalBalanceDue += max(0.0, $orderBalance);
        }

        try {
            $eventDate = new DateTimeImmutable((string)($order->event_date ?? ''));
            if ($eventDate >= $today) {
                $upcomingEvents[] = $order;
            }
        } catch (Throwable $e) {
            // Ignore malformed legacy dates; the full orders table remains available.
        }
    }

    usort($upcomingEvents, static function ($a, $b): int {
        return strcmp((string)($a->event_date ?? ''), (string)($b->event_date ?? ''))
            ?: strcmp((string)($a->start_time ?? ''), (string)($b->start_time ?? ''));
    });

    $email = method_exists($user, 'getEmail') ? (string)$user->getEmail() : '';
    $ticketCount = 0;
    $activeTicketCount = 0;
    if ($email !== '') {
        foreach ($ticketsRepo->getByBuyerEmail($email) as $ticket) {
            $ticketCount++;
            if (($ticket->updated_at ?? null) === ($ticket->created_at ?? null)) {
                $activeTicketCount++;
            }

            if (!empty($ticket->venue_event_id)) {
                $ticket->event = $venueEventsRepo->getOne(['id' => $ticket->venue_event_id]);
            }
        }
    }

    $musicSessions = array_slice($sessionsRepo->getPublicSessionsByPlatform(null, null, null), 0, 3);

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'user' => $user,
        'orders' => $orders,
        'pendingPayments' => array_slice($pendingPayments, 0, 3),
        'pendingSignatures' => array_slice($pendingSignatures, 0, 3),
        'upcomingEvents' => array_slice($upcomingEvents, 0, 4),
        'musicSessions' => $musicSessions,
        'stats' => [
            'orders' => count($orders),
            'pending_payments' => count($pendingPayments),
            'pending_signatures' => count($pendingSignatures),
            'upcoming_events' => count($upcomingEvents),
            'tickets' => $ticketCount,
            'active_tickets' => $activeTicketCount,
            'balance_due' => round($totalBalanceDue, 2),
        ],
        'canSwitchToTeam' => (bool)$activeTeamContract,
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    $newLevel = (int)($_POST['level'] ?? 0);

    if (!$user || $newLevel !== 4) {
        LocationUtils::redirectInternal('panel/home');
        return;
    }

    $activeTeamContract = level5ActiveTeamContract((int)$user->getId());
    if (!$activeTeamContract) {
        MessageUtil::setMessage('Team access requires an active VNV Events team contract.');
        LocationUtils::redirectInternal('panel/home');
        return;
    }

    $institution = null;
    $ownerId = (int)($activeTeamContract->id_owner ?? 0);
    if ($ownerId > 0) {
        $institution = (new InstitutionProfileRepository())->getByOwner($ownerId);
    }

    if ($institution) {
        $_SESSION['current_institution_id'] = (int)$institution->id;
        $_SESSION['current_institution_role'] = 'team';
        LoginService::reloadUserPermissions((int)$institution->id);
    }

    $repo = new UserRepository();
    $repo->update(['level' => $newLevel], ['id' => $user->getId()]);

    $user->setLevel($newLevel);
    LoginService::setSession($user);

    MessageUtil::setMessage('Dashboard updated.');
        LocationUtils::redirectInternal('panel/home');
});

$router->run();
