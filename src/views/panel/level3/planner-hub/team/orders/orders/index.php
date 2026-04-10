<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\OrdersRepository;
use App\Repositories\UserRepository;
use App\Repositories\OrdersStaffInvitesRepository;
use App\Repositories\InstitutionProfileRepository;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;

$router = new Router();

// GET - Mostrar órdenes invitadas
$router->get(callback: function () {
    $user = LoginService::getSession();

    $repo = new OrdersRepository();
    $clientRepo = new UserRepository();

    $orders = $repo->getOrdersByInvitation($user->getId());
    $clients = $clientRepo->getAllBy(["level" => 5]);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "orders" => $orders,
        "clients" => $clients
    ]);
});

// POST - Confirmar o rechazar invitación
$router->post(callback: function () {
    $user = LoginService::getSession();
    $inviteRepo = new OrdersStaffInvitesRepository();

    $idOrder = $_POST['id_order'] ?? null;
    $isConfirmed = $_POST['is_confirmed'] ?? null;

    if ($idOrder !== null && $isConfirmed !== null) {
        $orderRepo = new OrdersRepository();
        $remainingSlots = $orderRepo->getRemainingTeamSlots($idOrder);

        if ((int)$isConfirmed === 1) {
            if ($remainingSlots == 0) {
                MessageUtil::setMessage("⚠️ The team quota has already been reached. If any member cancels, a slot will be freed up.");
                LocationUtils::reload();
            }
        }

        $inviteRepo->confirmInvitation(
            id_order: (int)$idOrder,
            id_user: $user->getId(),
            is_confirmed: (int)$isConfirmed
        );
    }

    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
});

$router->run();


 
