<?php

use App\Repositories\UserRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\VenueRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersPaymentsRepository;
use App\Repositories\MembershipPaymentsRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\UserContext;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();
    
    if ($user->getLevel() != 1) {
        MessageUtil::setMessage("Access denied. Admin access required.");
        LocationUtils::redirectInternal("panel/dashboard");
    }

    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-t');
    $search = $_GET['search'] ?? '';
    $level = $_GET['level'] ?? '';

    $userRepo = new UserRepository();
    $db = new Connection();
    
    $whereConditions = [];
    $params = [];
    
    if (!empty($startDate) && !empty($endDate)) {
        $whereConditions[] = "membership_due_date >= :start_date AND membership_due_date <= :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(name LIKE :search OR lastname LIKE :search OR email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if (!empty($level)) {
        $whereConditions[] = "level = :level";
        $params[':level'] = $level;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    $db->query("SELECT * FROM users $whereClause ORDER BY id DESC");
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    $db->execute();
    $users = $db->fetchAll();
    
    $serviceRepo = new ServiceRepository();
    $venueRepo = new VenueRepository();
    $orderRepo = new OrdersRepository();
    $paymentRepo = new OrdersPaymentsRepository();
    $membershipRepo = new MembershipPaymentsRepository();
    
    $reports = [];
    
    foreach ($users as $user) {
        $report = [
            'user' => $user,
            'vendor_profile' => null,
            'venue_profile' => null,
            'team_members_count' => 0,
            'monthly_revenue' => 0,
            'orders_processed' => 0,
            'membership_status' => 'free',
            'membership_expires' => null,
            'profile_renewal_date' => null
        ];
        
        $vendorProfile = $serviceRepo->getOne(['user_id' => $user->id]);
        if ($vendorProfile) {
            $report['vendor_profile'] = $vendorProfile;
            $report['profile_renewal_date'] = $vendorProfile->renewal_date ?? null;
        }
        
        $venueProfile = $venueRepo->getOne(['user_id' => $user->id]);
        if ($venueProfile) {
            $report['venue_profile'] = $venueProfile;
            if (!$report['profile_renewal_date']) {
                $report['profile_renewal_date'] = $venueProfile->renewal_date ?? null;
            }
        }
        
        try {
            $db->query("SELECT COUNT(*) as count FROM users WHERE id_owner = :owner_id");
            $db->bind(":owner_id", $user->id);
            $db->execute();
            $result = $db->fetchAll()[0] ?? null;
            $report['team_members_count'] = (int)($result->count ?? 0);
        } catch (\Throwable $e) {
            $report['team_members_count'] = 0;
        }
        
        try {
            $monthStart = date('Y-m-01', strtotime($startDate));
            $monthEnd = date('Y-m-t', strtotime($startDate));
            
            $db->query("
                SELECT COALESCE(SUM(op.amount), 0) as total_revenue 
                FROM orders_payments op 
                INNER JOIN orders o ON o.id = op.id_order 
                WHERE o.id_owner = :owner_id 
                AND op.paid_at >= :month_start 
                AND op.paid_at <= :month_end
            ");
            $db->bind(":owner_id", $user->id);
            $db->bind(":month_start", $monthStart . ' 00:00:00');
            $db->bind(":month_end", $monthEnd . ' 23:59:59');
            $db->execute();
            $result = $db->fetchAll()[0] ?? null;
            $report['monthly_revenue'] = (float)($result->total_revenue ?? 0);
        } catch (\Throwable $e) {
            $report['monthly_revenue'] = 0;
        }
        
        try {
            $monthStart = date('Y-m-01', strtotime($startDate));
            $monthEnd = date('Y-m-t', strtotime($startDate));
            
            $db->query("
                SELECT COUNT(*) as count 
                FROM orders 
                WHERE id_owner = :owner_id 
                AND created_at >= :month_start 
                AND created_at <= :month_end
            ");
            $db->bind(":owner_id", $user->id);
            $db->bind(":month_start", $monthStart . ' 00:00:00');
            $db->bind(":month_end", $monthEnd . ' 23:59:59');
            $db->execute();
            $result = $db->fetchAll()[0] ?? null;
            $report['orders_processed'] = (int)($result->count ?? 0);
        } catch (\Throwable $e) {
            $report['orders_processed'] = 0;
        }
        
        try {
            $db->query("
                SELECT * FROM membership_payments 
                WHERE id_user = :user_id 
                AND status = 'paid' 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $db->bind(":user_id", $user->id);
            $db->execute();
            $membership = $db->fetchAll()[0] ?? null;
            
            if ($membership) {
                $report['membership_status'] = 'paid';
                $report['membership_expires'] = $membership->expires_at ?? null;
            }
        } catch (\Throwable $e) {
            // Mantener como 'free'
        }
        
        $reports[] = $report;
    }
    
    usort($reports, function($a, $b) {
        $dateA = $a['user']->membership_due_date ?? '1970-01-01';
        $dateB = $b['user']->membership_due_date ?? '1970-01-01';
        return strtotime($dateB) - strtotime($dateA);
    });

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "base_url" => LocationUtils::getBasePath(),
        "reports" => $reports,
        "start_date" => $startDate,
        "end_date" => $endDate,
        "search" => $search,
        "level" => $level,
        "total_users" => count($reports)
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
