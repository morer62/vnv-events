<?php

use App\Repositories\UserRepository;
use App\Repositories\AffiliateCommissionsRepository;
use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(function () {
    try {
        $user = LoginService::getSession();
        if (!$user) {
            \App\Utils\LocationUtils::redirectInternal("/login");
        }
        
        $repoCommissions = new AffiliateCommissionsRepository();
        $repoUsers = new UserRepository();

        $affiliateId = $_GET["affiliate_id"] ?? null;
        $from = $_GET["from"] ?? "";
        $to = $_GET["to"] ?? "";

        // Obtener comisiones agrupadas por afiliado
        $groupedCommissions = $repoCommissions->getGroupedPendingCommissions();
        
        // Filtrar por afiliado si se especifica
        if ($affiliateId) {
            $groupedCommissions = array_filter($groupedCommissions, function($item) use ($affiliateId) {
                return $item->referrer_id == $affiliateId;
            });
        }

        // Filtrar por fechas si se especifican
        if ($from || $to) {
            $groupedCommissions = array_filter($groupedCommissions, function($item) use ($from, $to) {
                $oldestDate = date('Y-m-d', strtotime($item->oldest_commission));
                $newestDate = date('Y-m-d', strtotime($item->newest_commission));
                
                if ($from && $oldestDate < $from) return false;
                if ($to && $newestDate > $to) return false;
                
                return true;
            });
        }

        // Obtener todos los afiliados para el filtro
        $affiliates = $repoUsers->getAllBy(["level" => 1]); // Solo usuarios nivel 1 pueden ser afiliados
        
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "affiliates" => $affiliates,
            "affiliateId" => $affiliateId,
            "from" => $from,
            "to" => $to,
            "groupedCommissions" => $groupedCommissions
        ]);
        
    } catch (\Exception $e) {
        error_log("Error in commissions pending: " . $e->getMessage());
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "affiliates" => [],
            "affiliateId" => null,
            "from" => "",
            "to" => "",
            "groupedCommissions" => [],
            "error" => "Error loading commissions data: " . $e->getMessage()
        ]);
    }
});

try {
    $router->run();
} catch (\Exception $e) {
    error_log("Router error in commissions pending: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
}
