<?php

use App\Services\LoginService;
use App\Utils\Router;
use App\Repositories\UserDeletionImmediatelyRepository;
use App\Utils\LocationUtils;
use App\Utils\JsonResponse;

$router = new Router();

$router->post(function () {
    $user = LoginService::getSession();
    $repo = new UserDeletionImmediatelyRepository();


    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    if (isset($_POST["confirm_hard_delete"]) && $_POST["confirm_hard_delete"] === "yes") {
        try {


            $repo->deactivateAccountImmediately($user->getId());
            LocationUtils::redirectInternal('/login');


            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'redirect' => '/login'
                ]);
                exit;
            }

            return;
        } catch (\Exception $e) {
            error_log("ERROR CRÍTICO al desactivar cuenta: " . $e->getMessage());

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al desactivar la cuenta: ' . $e->getMessage()
                ]);
                exit;
            }

            LocationUtils::redirectInternal("panel/settings?error=1");
            return;
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Parámetros inválidos'
        ]);
        exit;
    }

    LocationUtils::redirectInternal("panel/settings");
});

$router->run();