<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Services\LoginService;
use App\Repositories\OrdersRepository;

$router = new Router();

/** Ruta al listado de órdenes (convención global) */
function orders_list_path(): string {
    return 'panel/planner-hub/management/orders/orders/';
}

/** Path actual sin querystring */
function current_path(): string {
    return rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
}

/**
 * GET: Render hub de equipo
 */
$router->get(function () {
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        MessageUtil::setMessage('❌ Missing order id.');
        LocationUtils::redirectInternal(orders_list_path());
    }

    // Cargar la orden (scoped por owner por seguridad)
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $orderRepo->getOneByIdAndOwner($id, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $orderRepo->getOne([
            'id' => $id,
            'id_owner' => $user->getOwner(),
        ]);
    }

    if (!$order) {
        MessageUtil::setMessage('❌ Order not found or not accessible.');
        LocationUtils::redirectInternal(orders_list_path());
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'id' => $id,
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

    $action = $_POST['__action'] ?? '';
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage('❌ Missing order id.');
        LocationUtils::redirectInternal(orders_list_path());
    }

    // Verificar acceso a la orden
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if ($currentInstitutionId) {
            $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
            $institution = $institutionRepo->getById($currentInstitutionId);
            if ($institution && $institution->id_owner) {
                $order = $orderRepo->getOneByIdAndOwner($id, $institution->id_owner);
            } else {
                $order = null;
            }
        } else {
            $order = null;
        }
    } else {
        $order = $orderRepo->getOne([
            'id' => $id,
            'id_owner' => $user->getOwner(),
        ]);
    }
    
    if (!$order) {
        MessageUtil::setMessage('❌ Order not found or not accessible.');
        LocationUtils::redirectInternal(orders_list_path());
    }

    switch ($action) {
        case 'update-team-needed':
            $total = isset($_POST['total_team_needed']) ? (int)$_POST['total_team_needed'] : 0;
            if ($total < 1) {
                MessageUtil::setMessage('⚠️ Please provide a valid team size (min 1).');
                LocationUtils::reload();
            }

            $orderRepo->update(
                ['total_team_needed' => $total],
                ['id' => $id, 'id_owner' => $user->getOwner()]
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
