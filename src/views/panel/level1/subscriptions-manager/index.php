<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\UserRepository;
use App\Repositories\AutopayTransactionRepository;
use App\Repositories\AutopaySettingRepository;
use App\Repositories\Connection;

$router = new Router();

$router->get(function () {
    $userRepo = new UserRepository();
    $autopayTxRepo = new AutopayTransactionRepository();
    $autopaySettingRepo = new AutopaySettingRepository();
    $db = new Connection();

    // === Subscription Analytics ===
    
    // Get total active subscriptions (users with valid membership_due_date)
    $db->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE membership_due_date IS NOT NULL 
        AND membership_due_date >= CURDATE()
        AND level IN (2, 3)
    ");
    $activeSubscriptions = $db->fetchOne()->count ?? 0;

    // Get total expired subscriptions
    $db->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE membership_due_date IS NOT NULL 
        AND membership_due_date < CURDATE()
        AND level IN (2, 3)
    ");
    $expiredSubscriptions = $db->fetchOne()->count ?? 0;

    // Get subscription breakdown by plan type (from autopay_settings)
    // Excluir usuarios con días gratis (sin pagos)
    $db->query("
        SELECT 
            CASE 
                WHEN (SELECT COUNT(*) FROM payments_all WHERE concept = 'Membership' AND user_id = u.id) = 0 
                     AND u.membership_type = 'FREE' 
                THEN 'free_trial'
                ELSE COALESCE(aps.plan_type, 'monthly')
            END as plan_type,
            COUNT(*) as count
        FROM users u
        LEFT JOIN autopay_settings aps ON u.id = aps.user_id
        WHERE u.membership_due_date IS NOT NULL 
        AND u.membership_due_date >= CURDATE()
        AND u.level IN (2, 3)
        GROUP BY plan_type
    ");
    $planBreakdown = $db->fetchAll();

    // Convert to associative array for easier access
    $planStats = [
        'monthly' => 0,
        'quarterly' => 0,
        'annual' => 0,
        'free_trial' => 0
    ];
    foreach ($planBreakdown as $plan) {
        if (isset($planStats[$plan->plan_type])) {
            $planStats[$plan->plan_type] = $plan->count;
        }
    }

    // Get autopay enrollment rate
    $db->query("
        SELECT COUNT(*) as count 
        FROM autopay_settings 
        WHERE enabled = 1
    ");
    $autopayEnabled = $db->fetchOne()->count ?? 0;
    $autopayRate = $activeSubscriptions > 0 ? round(($autopayEnabled / $activeSubscriptions) * 100, 1) : 0;

    // === Revenue Metrics ===
    
    // Calculate monthly recurring revenue (MRR) estimation
    $monthlyPrice = floatval($_ENV['MEMBERSHIP_PLAN_MONTHLY'] ?? 16.99);
    $quarterlyPrice = floatval($_ENV['MEMBERSHIP_PLAN_QUARTERLY'] ?? 45.99);
    $annualPrice = floatval($_ENV['MEMBERSHIP_PLAN_ANNUAL'] ?? 169.99);

    $mrr = ($planStats['monthly'] * $monthlyPrice) +
           ($planStats['quarterly'] * ($quarterlyPrice / 3)) +
           ($planStats['annual'] * ($annualPrice / 12));
    
    // Total revenue this month from autopay transactions
    $db->query("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM autopay_transactions 
        WHERE status = 'success' 
        AND MONTH(processed_at) = MONTH(CURDATE())
        AND YEAR(processed_at) = YEAR(CURDATE())
    ");
    $monthlyRevenue = $db->fetchOne()->total ?? 0;

    // === Recent Transactions ===
    $db->query("
        SELECT 
            at.*,
            u.name,
            u.lastname,
            u.email
        FROM autopay_transactions at
        JOIN users u ON at.user_id = u.id
        ORDER BY at.processed_at DESC
        LIMIT 10
    ");
    $recentTransactions = $db->fetchAll();

    // === Users Expiring Soon ===
    $db->query("
        SELECT 
            id,
            name,
            lastname,
            email,
            membership_due_date,
            DATEDIFF(membership_due_date, CURDATE()) as days_remaining
        FROM users 
        WHERE membership_due_date IS NOT NULL 
        AND membership_due_date >= CURDATE()
        AND membership_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND level IN (2, 3)
        ORDER BY membership_due_date ASC
        LIMIT 10
    ");
    $expiringSoon = $db->fetchAll();

    // === Failed Transactions (Last 30 days) ===
    $db->query("
        SELECT 
            at.*,
            u.name,
            u.lastname,
            u.email
        FROM autopay_transactions at
        JOIN users u ON at.user_id = u.id
        WHERE at.status = 'failed'
        AND at.processed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY at.processed_at DESC
        LIMIT 10
    ");
    $failedTransactions = $db->fetchAll();

    // === Users by Plan Type ===
    // All users with active memberships
    $db->query("
        SELECT 
            u.id,
            u.name,
            u.lastname,
            u.email,
            u.phone,
            u.membership_due_date,
            u.level,
            u.membership_type,
            COALESCE(aps.plan_type, 'monthly') as plan_type,
            DATEDIFF(u.membership_due_date, CURDATE()) as days_until_expiry,
            aps.enabled as autopay_enabled,
            (SELECT COUNT(*) FROM payments_all WHERE concept = 'Membership' AND user_id = u.id) as has_payments
        FROM users u
        LEFT JOIN autopay_settings aps ON u.id = aps.user_id
        WHERE u.membership_due_date IS NOT NULL 
        AND u.membership_due_date >= CURDATE()
        AND u.level IN (2, 3)
        ORDER BY u.membership_due_date ASC
    ");
    $allActiveUsers = $db->fetchAll();
    
    // Corregir plan_type para usuarios con días gratis (sin pagos)
    foreach ($allActiveUsers as $user) {
        if ($user->has_payments == 0 && $user->membership_type === 'FREE') {
            $user->plan_type = 'free_trial';
        }
    }

    // Group users by plan type
    $usersByPlan = [
        'all' => $allActiveUsers,
        'monthly' => [],
        'quarterly' => [],
        'annual' => [],
        'free_trial' => []
    ];

    foreach ($allActiveUsers as $user) {
        if (isset($usersByPlan[$user->plan_type])) {
            $usersByPlan[$user->plan_type][] = $user;
        }
    }

    // === Expired Users ===
    $db->query("
        SELECT 
            u.id,
            u.name,
            u.lastname,
            u.email,
            u.phone,
            u.membership_due_date,
            u.level,
            u.membership_type,
            COALESCE(aps.plan_type, 'monthly') as last_plan_type,
            DATEDIFF(CURDATE(), u.membership_due_date) as days_expired,
            (SELECT COUNT(*) FROM payments_all WHERE concept = 'Membership' AND user_id = u.id) as has_payments
        FROM users u
        LEFT JOIN autopay_settings aps ON u.id = aps.user_id
        WHERE u.membership_due_date IS NOT NULL 
        AND u.membership_due_date < CURDATE()
        AND u.level IN (2, 3)
        ORDER BY u.membership_due_date DESC
        LIMIT 50
    ");
    $expiredUsers = $db->fetchAll();
    
    // Corregir plan_type para usuarios expirados con días gratis
    foreach ($expiredUsers as $user) {
        if ($user->has_payments == 0 && $user->membership_type === 'FREE') {
            $user->last_plan_type = 'free_trial';
        }
    }


    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'stats' => [
            'active' => $activeSubscriptions,
            'expired' => $expiredSubscriptions,
            'autopay_enabled' => $autopayEnabled,
            'autopay_rate' => $autopayRate,
            'plans' => $planStats,
            'mrr' => number_format($mrr, 2),
            'monthly_revenue' => number_format($monthlyRevenue, 2),
        ],
        'recent_transactions' => $recentTransactions,
        'expiring_soon' => $expiringSoon,
        'failed_transactions' => $failedTransactions,
        'users_by_plan' => $usersByPlan,
        'expired_users' => $expiredUsers,
        'pricing' => [
            'monthly' => $monthlyPrice,
            'quarterly' => $quarterlyPrice,
            'annual' => $annualPrice,
        ]
    ]);
});

// 🔹 Agregar días manualmente a un usuario
$router->post(function () {
    if (!isset($_POST['action'])) {
        return;
    }
    
    $userRepo = new UserRepository();
    $db = new Connection();
    
    if ($_POST['action'] === 'add_days') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $days = (int)($_POST['days'] ?? 0);
        
        if ($userId <= 0 || $days <= 0) {
            \App\Utils\MessageUtil::setMessage("Invalid user ID or days");
            \App\Utils\LocationUtils::reload();
            return;
        }
        
        // Obtener fecha actual de vencimiento
        $user = $userRepo->getOneWithoutOwnership(['id' => $userId]);
        if (!$user) {
            \App\Utils\MessageUtil::setMessage("User not found");
            \App\Utils\LocationUtils::reload();
            return;
        }
        
        $currentDueDate = $user->membership_due_date ?? date('Y-m-d');
        $newDueDate = date('Y-m-d', strtotime("$currentDueDate + $days days"));
        
        // Actualizar fecha de vencimiento
        $userRepo->update(['membership_due_date' => $newDueDate], ['id' => $userId]);
        
        \App\Utils\MessageUtil::setMessage("✅ Successfully added $days days to user. New expiration date: $newDueDate");
        \App\Utils\LocationUtils::reload();
        return;
    }
    
    if ($_POST['action'] === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        
        if ($userId <= 0) {
            \App\Utils\MessageUtil::setMessage("Invalid user ID");
            \App\Utils\LocationUtils::reload();
            return;
        }
        
        // Verificar que el usuario existe
        $user = $userRepo->getOneWithoutOwnership(['id' => $userId]);
        if (!$user) {
            \App\Utils\MessageUtil::setMessage("User not found");
            \App\Utils\LocationUtils::reload();
            return;
        }
        
        // Eliminar permanentemente el usuario y datos relacionados
        // Primero eliminar datos relacionados
        $db->query("DELETE FROM payments_all WHERE user_id = ?");
        $db->bind(1, $userId);
        $db->execute();
        
        $db->query("DELETE FROM autopay_settings WHERE user_id = ?");
        $db->bind(1, $userId);
        $db->execute();
        
        $db->query("DELETE FROM autopay_transactions WHERE user_id = ?");
        $db->bind(1, $userId);
        $db->execute();
        
        $db->query("DELETE FROM user_cards WHERE id_user = ?");
        $db->bind(1, $userId);
        $db->execute();
        
        // Finalmente eliminar el usuario
        $userRepo->delete(['id' => $userId]);
        
        \App\Utils\MessageUtil::setMessage("✅ User permanently deleted");
        \App\Utils\LocationUtils::reload();
        return;
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
