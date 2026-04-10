<?php

use App\Repositories\UserRepository;
use App\Repositories\ClientsUsersRepository;
use App\Services\LoginService;
use App\Utils\Cors;
use App\Utils\JsonResponse;
use App\Utils\Router;

Cors::handle();

$router = new Router();

$router->get(function () {
    $query = $_GET["query"] ?? "";
    $session = LoginService::getSession();

    if (!$query || !$session) {
        return JsonResponse::createResponse(data: []);
    }

    $userRepo = new UserRepository();
    $assocRepo = new ClientsUsersRepository();

    $results = $userRepo->searchClientsByEmail($query);

    $data = array_map(function ($user) use ($assocRepo, $session) {
        return [
            "id" => $user->id,
            "name" => $user->name . " " . $user->lastname,
            "email" => $user->email,
            "linked" => $assocRepo->exists($user->id, $session->getIdOwner())
        ];
    }, $results);

    return JsonResponse::createResponse(data: $data);
});

$router->run();
