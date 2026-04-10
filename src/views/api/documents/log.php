<?php

use App\Repositories\DocumentsLogsRepository;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;
use App\Services\LoginService;

Cors::handle();
$router = new Router();

$router->post(function () {
    $user = LoginService::getSession();

    $repo = new DocumentsLogsRepository();

    $data = [
        "id_order"   => $_POST["id_order"] ?? null,
        "id_user"    => $user?->getId() ?? null,
        "doc_type"   => $_POST["doc_type"] ?? 'unknown',
        "file_path"  => $_POST["file_path"] ?? '',
        "hash"       => $_POST["hash"] ?? null,
        "ip"         => $_SERVER["REMOTE_ADDR"] ?? null,
        "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
        "extra"      => json_encode($_POST["extra"] ?? []),
    ];

    $repo->add($data);

    return JsonResponse::createResponse([
        "message" => "Document log created"
    ]);
});

$router->run();
