<?php

use App\Services\LoginService;
use App\Repositories\UserRepository;
use App\Repositories\UserBillingInfoRepository;
use App\Repositories\UserCardsRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

// 🔹 Render vista principal
$router->get(function () {
    $user = LoginService::getSession();
    
    // Primero verificar si tiene información de billing
    $billingRepo = new UserBillingInfoRepository();
    $billing = $billingRepo->getByUserId($user->getId());
    
    if (!$billing) {
        LocationUtils::redirectInternal("panel/billing");
        return;
    }
    
    // Luego verificar si tiene tarjeta configurada
    $cardRepo = new UserCardsRepository();
    $cardCount = $cardRepo->countCards($user->getId());
    
    if ($cardCount === 0) {
        LocationUtils::redirectInternal("panel/cards");
        return;
    }
    
    // Verificar si está en período de prueba gratis
    $isFreeTrial = false;
    $trialDaysRemaining = 0;
    $trialExpirationDate = null;
    
    if ($user->getMembershipDueDate()) {
        $dueDate = new \DateTime($user->getMembershipDueDate());
        $now = new \DateTime();
        
        // Verificar si tiene pagos de membresía
        $db = new \App\Repositories\Connection();
        $db->query("SELECT COUNT(*) as total FROM payments_all WHERE concept = 'Membership' AND user_id = ?");
        $db->bind(1, $user->getId());
        $result = $db->fetchOne();
        $hasPayments = ($result !== false && isset($result->total) && $result->total > 0);
        
        // Si nunca ha pagado (membership_type = FREE) y la fecha de vencimiento está en el futuro, está en período de prueba
        if (!$hasPayments && $user->getMembershipType() === 'FREE' && $dueDate >= $now) {
            $isFreeTrial = true;
            $trialExpirationDate = $user->getMembershipDueDate();
            $trialDaysRemaining = max(0, $dueDate->diff($now)->days);
        }
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "user" => $user,
        "isFreeTrial" => $isFreeTrial,
        "trialDaysRemaining" => $trialDaysRemaining,
        "trialExpirationDate" => $trialExpirationDate
    ]);
});

// 🔹 Cambiar nivel de usuario y redirigir
$router->post(function () {
    $user = LoginService::getSession();
    $newLevel = (int)($_POST['level'] ?? 0);

    if (!$user || !in_array($newLevel, [2, 3, 4, 5])) {
        LocationUtils::redirectInternal("panel/home");
        return;
    }

    // Update en base de datos
    $repo = new UserRepository();
    $repo->update(["level" => $newLevel], ["id" => $user->getId()]);

    // Refrescar objeto en sesión
    $user->setLevel($newLevel);
    LoginService::setSession($user);

    // 🔹 Redirigir directo al dashboard
    LocationUtils::redirectInternal("panel/");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
