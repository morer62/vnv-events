<?php

use App\Services\LoginService;
use App\Services\TranslationService;
use App\Repositories\OrdersRepository;
use App\Repositories\OrdersServiceRepository;
use App\Repositories\OrdersServicesAssignedRepository;
use App\Repositories\OrderSuborderServicesAssignedRepository;
use App\Repositories\OrdersSuborderRepository;
use App\Repositories\MenuCategoriesRepository;
use App\Repositories\MenuCategoryItemsRepository;
use App\Repositories\OrderServiceMenuSelectionsRepository;
use App\Utils\MessageUtil;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function is_ajax_request(): bool {
    return (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1')
    );
}

function orders_team_path(): string {
    return 'panel/planner-hub/management/orders/orders/';
}

function ensure_order_access(): ?object {
    $orderId = (int)($_GET['id'] ?? $_POST['id_order'] ?? 0);
    if ($orderId <= 0) {
        TranslationService::detectLocale();
        MessageUtil::setMessage(TranslationService::trans('planner_hub.missing_order_id'));
        LocationUtils::redirectInternal(orders_team_path());
        return null;
    }
    $user = LoginService::getSession();
    $orderRepo = new OrdersRepository();
    if ($user->getLevel() === 4) {
        $currentInstitutionId = $_SESSION['current_institution_id'] ?? null;
        if (!$currentInstitutionId) return null;
        $institutionRepo = new \App\Repositories\InstitutionProfileRepository();
        $institution = $institutionRepo->getById($currentInstitutionId);
        if (!$institution || !$institution->id_owner) return null;
        $order = $orderRepo->getOneByIdAndOwner($orderId, $institution->id_owner);
    } else {
        $order = $orderRepo->getOne(['id' => $orderId, 'id_owner' => $user->getOwner()]);
    }
    if (!$order) {
        MessageUtil::setMessage('Order not found or not accessible.');
        LocationUtils::redirectInternal(orders_team_path());
        return null;
    }
    return $order;
}

$router->get(function () {
    $order = ensure_order_access();
    if (!$order) return;

    $orderId = (int)$order->id;
    $assignedRepo = new OrdersServicesAssignedRepository();
    $suborderServicesRepo = new OrderSuborderServicesAssignedRepository();
    $suborderRepo = new OrdersSuborderRepository();
    $serviceRepo = new OrdersServiceRepository();
    $menuCategoriesRepo = new MenuCategoriesRepository();
    $menuItemsRepo = new MenuCategoryItemsRepository();
    $menuSelectionsRepo = new OrderServiceMenuSelectionsRepository();

    // Servicios de la orden principal
    $servicesAssigned = $assignedRepo->getAllBy(["id_order" => $orderId]);
    $services = [];
    foreach ($servicesAssigned as $assigned) {
        $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
        $selections = $menuSelectionsRepo->getByService($orderId, null, $assigned->id_service, 'main_order');
        $services[] = [
            "name" => $service ? $service->name : "Unknown Service",
            "id_service" => $assigned->id_service,
            "source" => "main_order",
            "suborder_id" => null,
            "selections" => $selections,
        ];
    }

    // Servicios de las subórdenes
    $suborders = $suborderRepo->getByOrder($orderId);
    $suborderServices = [];
    foreach ($suborders as $suborder) {
        $suborderServicesAssigned = $suborderServicesRepo->getServicesWithDetails($suborder->id);
        foreach ($suborderServicesAssigned as $assigned) {
            $service = $serviceRepo->getByIdWithoutOwnershipCheck($assigned->id_service);
            $selections = $menuSelectionsRepo->getByService($orderId, (int)$suborder->id, $assigned->id_service, 'suborder');
            $suborderServices[] = [
                "name" => $service ? $service->name : ($assigned->service_name ?? "Unknown Service"),
                "id_service" => $assigned->id_service,
                "source" => "suborder",
                "suborder_id" => $suborder->id,
                "selections" => $selections,
            ];
        }
    }

    $menuCategories = $menuCategoriesRepo->getAllOrdered();
    $menuItemsByCategory = $menuItemsRepo->getAllGroupedByCategory();

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'id' => $orderId,
        'order' => $order,
        'order_id' => $orderId,
        'services' => $services,
        'suborderServices' => $suborderServices,
        'suborders' => $suborders,
        'menu_categories' => $menuCategories,
        'menu_items_by_category' => $menuItemsByCategory,
    ]);
});

$router->post(function () {
    TranslationService::detectLocale();
    $order = ensure_order_access();
    if (!$order) return;

    $orderId = (int)$order->id;
    $action = $_POST['__action'] ?? '';

    if ($action === 'add_menu_selections') {
        $isAjax = is_ajax_request();
        $idSuborder = !empty($_POST['id_suborder']) ? (int)$_POST['id_suborder'] : null;
        $idService = (int)($_POST['id_service'] ?? 0);
        $source = ($_POST['source'] ?? '') === 'suborder' ? 'suborder' : 'main_order';
        $items = isset($_POST['id_menu_category_item']) ? (array)$_POST['id_menu_category_item'] : [];

        if ($idService <= 0) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => TranslationService::trans('planner_hub.invalid_request')], JSON_UNESCAPED_UNICODE);
                return;
            }
            MessageUtil::setMessage(TranslationService::trans('planner_hub.invalid_request'));
            LocationUtils::redirectInternal(orders_team_path() . 'team_comunication/menu?id=' . $orderId);
            return;
        }
        if ($source === 'suborder' && $idSuborder === null) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => TranslationService::trans('planner_hub.invalid_request')], JSON_UNESCAPED_UNICODE);
                return;
            }
            MessageUtil::setMessage(TranslationService::trans('planner_hub.invalid_request'));
            LocationUtils::redirectInternal(orders_team_path() . 'team_comunication/menu?id=' . $orderId);
            return;
        }

        $repo = new OrderServiceMenuSelectionsRepository();
        $repo->deleteForService($orderId, $idSuborder, $idService, $source);
        foreach ($items as $idItem) {
            $idItem = (int)$idItem;
            if ($idItem > 0) {
                $repo->addSelection($orderId, $idSuborder, $idService, $idItem, $source);
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => TranslationService::trans('planner_hub.saved'),
                'selected_count' => count($items)
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        MessageUtil::setMessage(TranslationService::trans('planner_hub.saved'));
        LocationUtils::redirectInternal(orders_team_path() . 'team_comunication/menu?id=' . $orderId);
        return;
    }

    if ($action === 'remove_menu_selection') {
        $idSelection = (int)($_POST['id_selection'] ?? 0);
        if ($idSelection > 0) {
            $repo = new OrderServiceMenuSelectionsRepository();
            $repo->delete(['id' => $idSelection]);
        }
        MessageUtil::setMessage(TranslationService::trans('planner_hub.saved'));
        LocationUtils::redirectInternal(orders_team_path() . 'team_comunication/menu?id=' . $orderId);
        return;
    }

    MessageUtil::setMessage(TranslationService::trans('planner_hub.unsupported_action'));
    LocationUtils::redirectInternal(orders_team_path() . 'team_comunication/menu?id=' . $orderId);
});

$router->run();
