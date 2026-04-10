<?php

use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Repositories\RolesRepository;
use App\Repositories\PermissionsRepository;
use App\Utils\MessageUtil;

$router = new Router();

$router->get(function () {
    $rolesRepo = new RolesRepository();
    $permissionsRepo = new PermissionsRepository();

    $roles = $rolesRepo->getAllVisible();

    $custom_roles_count = array_reduce($roles, function ($carry, $role) {
        return $carry + ($role->id_owner != 0 ? 1 : 0);
    }, 0);
    $max_custom_roles_reached = $custom_roles_count >= 6;

    $role_id = $_GET['role_id'] ?? null;
    $selected_role = $role_id ? $rolesRepo->getOne(['id' => $role_id]) : null;
    $role_permission_ids = $role_id ? $rolesRepo->getPermissionIds($role_id) : [];

    $all = $permissionsRepo->getAllOrdered();
    $permissionsGrouped = [];

    foreach ($all as $perm) {
        $permissionsGrouped[$perm->module][] = $perm;
    }

    ksort($permissionsGrouped);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "roles" => $roles,
        "selected_role" => $selected_role,
        "permissions_grouped" => $permissionsGrouped,
        "role_permission_ids" => $role_permission_ids,
        "max_custom_roles_reached" => $max_custom_roles_reached
    ]);
});

$router->post(function () {
    $rolesRepo = new RolesRepository();

    if (isset($_POST['new_role']) && !empty($_POST['new_role'])) {
        $existing = $rolesRepo->getAllVisible();
        $custom_roles = array_filter($existing, fn($r) => $r->id_owner != 0);
        if (count($custom_roles) >= 6) {
            MessageUtil::setMessage("You can only create up to 6 custom roles.");
            LocationUtils::reload();
        }

        $rolesRepo->add([
            "name" => trim($_POST['new_role']),
            "id_owner" => LoginService::getSession()->getOwner()
        ]);
    }

    if (isset($_POST['role_id']) && isset($_POST['permissions'])) {
        $role = $rolesRepo->getOne(["id" => $_POST["role_id"]]);
        if ($role && $role->id_owner == 0) {
            MessageUtil::setMessage("System roles cannot be modified.");
            LocationUtils::reload();
        }

        $rolesRepo->updatePermissions(
            (int) $_POST['role_id'],
            array_map('intval', $_POST['permissions'])
        );
    }

    if (isset($_POST['delete_role_id'])) {
        $role = $rolesRepo->getOne(["id" => $_POST["delete_role_id"]]);
        if ($role && $role->id_owner == 0) {
            MessageUtil::setMessage("System roles cannot be deleted.");
            LocationUtils::reload();
        }
        $rolesRepo->delete(["id" => $_POST["delete_role_id"]]);
    }

    LocationUtils::reload();
});

$router->run();
