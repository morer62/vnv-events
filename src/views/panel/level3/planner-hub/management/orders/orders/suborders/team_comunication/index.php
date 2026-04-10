<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Services\LoginService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersSuborderRepository;

$router = new Router();

/** Ruta al listado de sub-órdenes (convención global) */
function suborders_list_path(): string {
    return 'panel/planner-hub/management/orders/orders/suborders/';
}

/** Path actual sin querystring */
function current_path(): string {
    return rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
}

/**
 * GET: Render hub de equipo para sub-órdenes
 */
$router->get(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();

    $suborderId = (int)($_GET['id'] ?? 0);
    if ($suborderId <= 0) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    // Cargar la sub-orden y verificar acceso
    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if (!$currentInstitutionId) {
            MessageUtil::setMessage('❌ No institution selected.');
            LocationUtils::redirectInternal(suborders_list_path());
        }

        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        if (!$institution) {
            MessageUtil::setMessage('❌ Institution not found.');
            LocationUtils::redirectInternal(suborders_list_path());
        }

        $institutionOwnerId = $institution->id_owner;
        $order = $orderRepo->getOneByIdAndOwner($suborder->id_order, $institutionOwnerId);
    } else {
        $order = $orderRepo->getOne([
            'id' => $suborder->id_order,
            'id_owner' => $user->getOwner(),
        ]);
    }

    if (!$order) {
        MessageUtil::setMessage('❌ Sub-order not accessible.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'id' => $suborderId,
        'suborder' => $suborder,
        'order' => $order,
        'current_location' => current_path(),
    ]);
});

/**
 * POST: acciones internas de este hub (ej: update-team-needed)
 */
$router->post(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    $suborderRepo = new OrdersSuborderRepository();

    $action = $_POST['__action'] ?? '';
    $suborderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    if ($suborderId <= 0) {
        MessageUtil::setMessage('❌ Missing sub-order id.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    $suborder = $suborderRepo->getOne(['id' => $suborderId]);
    if (!$suborder) {
        MessageUtil::setMessage('❌ Sub-order not found.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if (!$currentInstitutionId) {
            MessageUtil::setMessage('❌ No institution selected.');
            LocationUtils::redirectInternal(suborders_list_path());
        }

        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        if (!$institution) {
            MessageUtil::setMessage('❌ Institution not found.');
            LocationUtils::redirectInternal(suborders_list_path());
        }

        $institutionOwnerId = $institution->id_owner;
        $order = $orderRepo->getOneByIdAndOwner($suborder->id_order, $institutionOwnerId);
    } else {
        $order = $orderRepo->getOne([
            'id' => $suborder->id_order,
            'id_owner' => $user->getOwner(),
        ]);
    }

    if (!$order) {
        MessageUtil::setMessage('❌ Sub-order not accessible.');
        LocationUtils::redirectInternal(suborders_list_path());
    }

    switch ($action) {
        case 'update-team-needed':
            $total = isset($_POST['total_team_needed']) ? (int)$_POST['total_team_needed'] : 0;
            if ($total < 1) {
                MessageUtil::setMessage('⚠️ Please provide a valid team size (min 1).');
                LocationUtils::reload();
            }

            $suborderRepo->update(
                ['total_team_needed' => $total],
                ['id' => $suborderId]
            );

            MessageUtil::setMessage('✅ Team size updated.');
            LocationUtils::reload();
            
            break;

        default:
            MessageUtil::setMessage('Unsupported action.');
            LocationUtils::reload();
    }
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
