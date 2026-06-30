<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Services\LoginService;
use App\Services\AffiliateService;
use App\Repositories\StripeAccountsRepository;

$router = new Router();

$router->get(function () {
    $user = LoginService::getSession();
    if (!$user) {
        \App\Utils\LocationUtils::redirectInternal("/login");
    }

    \App\Utils\MessageUtil::setMessage('Affiliate tools are not available in the VNV Events client portal.');
    \App\Utils\LocationUtils::redirectInternal('panel/home');
    return;

    $affiliateService = new AffiliateService();
    $stripeRepo = new StripeAccountsRepository();
    
    // Obtener o crear código de afiliado
    $affiliateCode = $affiliateService->getOrCreateAffiliateCode($user->getId());
    
    // Obtener estadísticas del afiliado
    $stats = $affiliateService->getAffiliateStats($user->getId());
    
    // Obtener parámetros de búsqueda
    $search = $_GET['search'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    // Obtener referidos (usando la nueva lógica)
    $referrals = $affiliateService->getReferrals($user->getId(), $limit);
    $totalReferrals = count($referrals); // Simplificado para la nueva implementación
    
    // Obtener comisiones recientes
    $commissions = $affiliateService->getCommissions($user->getId(), 10);
    
    // Verificar cuenta de Stripe
    $stripeAccount = $stripeRepo->getByUser($user->getId());
    $hasStripeAccount = !empty($stripeAccount) && !empty($stripeAccount->stripe_account_id);
    
    // Calcular paginación básica
    $totalPages = ceil($totalReferrals / $limit);
    
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user,
        "affiliate_code" => $affiliateCode,
        "stats" => $stats ?: (object)[
            'affiliate_code' => $affiliateCode,
            'clicks' => 0,
            'conversions' => 0,
            'total_referrals' => 0,
            'confirmed_referrals' => 0,
            'pending_commissions' => 0,
            'approved_commissions' => 0,
            'paid_commissions' => 0,
            'total_commissions_earned' => 0,
            'last_referral_date' => null
        ],
        "referrals" => $referrals,
        "referrals_total" => $totalReferrals,
        "commissions" => $commissions,
        "stripe" => [
            "connected" => $hasStripeAccount,
            "status" => $stripeAccount->is_verified ?? 0 ? 'verified' : 'pending',
            "connect_url" => "/panel/planner-hub/management/payments/"
        ],
        "pagination" => [
            "current_page" => $page,
            "total_pages" => $totalPages,
            "has_prev" => $page > 1,
            "has_next" => $page < $totalPages,
            "prev_page" => $page - 1,
            "next_page" => $page + 1
        ],
        "search_params" => [
            "search" => $search,
            "date_from" => $dateFrom,
            "date_to" => $dateTo
        ]
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
