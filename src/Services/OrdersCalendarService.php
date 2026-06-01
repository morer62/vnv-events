<?php

namespace App\Services;

use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;

class OrdersCalendarService
{
    public function buildManagementCalendar(array $orders, array $clients, string $weekStart): array
    {
        return $this->buildCalendar($orders, $clients, $weekStart, 'management');
    }

    public function buildTeamCalendar(array $orders, string $weekStart): array
    {
        $clients = [];
        try {
            $clientRepo = new UserRepository();
            foreach ($orders as $order) {
                $clientId = (int)($order->id_client ?? 0);
                if ($clientId > 0 && !isset($clients[$clientId])) {
                    $client = $clientRepo->getByIdEvenIfAssociated($clientId);
                    if ($client) {
                        $clients[$clientId] = $client;
                    }
                }
            }
        } catch (\Throwable $e) {
            $clients = [];
        }

        return $this->buildCalendar($orders, array_values($clients), $weekStart, 'team');
    }

    public function buildClientCalendar(array $orders, string $weekStart): array
    {
        return $this->buildCalendar($orders, [], $weekStart, 'client');
    }

    public function fetchManagementOrders(?string $search, string $startDate, string $endDate): array
    {
        $repo = new OrdersRepository();
        return $repo->getFiltered2([
            ...LoginService::getUserIdAsArray(true),
            'is_archived' => 0,
        ], $search, $startDate, $endDate);
    }

    public function fetchTeamOrders(int $userId): array
    {
        return (new OrdersRepository())->getOrdersByInvitation($userId);
    }

    public function fetchClientOrders(int $clientId): array
    {
        return (new OrdersRepository())->getOrdersForClientWithoutOwnerFilter($clientId);
    }

    public function normalizeWeekStart(?string $value): string
    {
        $timestamp = $value ? strtotime($value) : false;
        if (!$timestamp) {
            $timestamp = time();
        }

        return date('Y-m-d', strtotime('monday this week', $timestamp));
    }

    public function weekEnd(string $weekStart): string
    {
        return date('Y-m-d', strtotime($weekStart . ' +6 days'));
    }

    private function buildCalendar(array $orders, array $clients, string $weekStart, string $mode): array
    {
        $clientMap = [];
        foreach ($clients as $client) {
            $clientMap[(int)$client->id] = $client;
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
            $days[$date] = [
                'date' => $date,
                'label' => date('D, M j', strtotime($date)),
                'orders' => [],
            ];
        }

        $noTime = [];
        $weekEnd = $this->weekEnd($weekStart);

        foreach ($orders as $order) {
            $date = substr((string)($order->event_date ?? ''), 0, 10);
            if ($date < $weekStart || $date > $weekEnd) {
                continue;
            }

            $client = $clientMap[(int)($order->id_client ?? 0)] ?? null;
            $item = [
                'id' => (int)($order->id ?? 0),
                'date' => $date,
                'time' => $this->displayTime($order),
                'sort_time' => $this->sortTime($order),
                'client' => $client ? trim(($client->name ?? '') . ' ' . ($client->lastname ?? '')) : $this->clientLabel($order, $mode),
                'email' => $client->email ?? '',
                'title' => $this->title($order),
                'status' => (string)($order->status_workflow ?? 'ORDER'),
                'address' => (string)($order->address ?? ''),
                'url' => $this->orderUrl($order, $mode),
                'details' => $this->detailsPayload($order, $client, $mode),
            ];

            if ($item['sort_time'] === null) {
                $noTime[] = $item;
                continue;
            }

            $days[$date]['orders'][] = $item;
        }

        foreach ($days as &$day) {
            usort($day['orders'], fn ($a, $b) => strcmp($a['sort_time'] ?? '99:99', $b['sort_time'] ?? '99:99'));
        }

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'previous_week' => date('Y-m-d', strtotime($weekStart . ' -7 days')),
            'next_week' => date('Y-m-d', strtotime($weekStart . ' +7 days')),
            'days' => array_values($days),
            'no_time' => $noTime,
        ];
    }

    private function displayTime(object $order): string
    {
        $start = $this->firstTime($order);
        if (!$start) {
            return 'No time assigned';
        }

        $end = $this->cleanTime((string)($order->end_time ?? ''));
        return $end ? $start . ' - ' . $end : $start;
    }

    private function sortTime(object $order): ?string
    {
        $raw = (string)($order->start_time ?? '');
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        return $timestamp ? date('H:i', $timestamp) : null;
    }

    private function firstTime(object $order): ?string
    {
        return $this->cleanTime((string)($order->start_time ?? ''));
    }

    private function cleanTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === '00:00:00') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('g:i A', $timestamp) : null;
    }

    private function title(object $order): string
    {
        $name = trim((string)($order->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'VNV Event Order #' . (int)($order->id ?? 0);
    }

    private function clientLabel(object $order, string $mode): string
    {
        if ($mode === 'client') {
            return trim((string)($order->institution->company_name ?? 'VNV Events'));
        }

        return 'Unknown client';
    }

    private function detailsPayload(object $order, ?object $client, string $mode): array
    {
        return [
            'id' => 'vnv-341' . (int)($order->id ?? 0),
            'client' => $client ? trim(($client->name ?? '') . ' ' . ($client->lastname ?? '')) : $this->clientLabel($order, $mode),
            'email' => $client->email ?? '',
            'eventDate' => (string)($order->event_date ?? ''),
            'time' => $this->displayTime($order),
            'status' => (string)($order->status_workflow ?? 'ORDER'),
            'address' => (string)($order->address ?? ''),
            'createdAt' => (string)($order->created_at ?? ''),
            'workflow' => (string)($order->status_workflow ?? ''),
            'url' => $this->orderUrl($order, $mode),
        ];
    }

    private function orderUrl(object $order, string $mode): string
    {
        $id = (int)($order->id ?? 0);
        if ($mode === 'team') {
            return '/panel/planner-hub/team/orders/orders/tasks?id=' . $id;
        }

        if ($mode === 'client') {
            return '/panel/planner-hub/orders/orders/files?id=' . $id;
        }

        return '/panel/planner-hub/management/orders/orders/edit?id=' . $id;
    }
}
