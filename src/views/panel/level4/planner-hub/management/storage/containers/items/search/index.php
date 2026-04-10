<?php

use App\Repositories\StorageItemRepository;
use App\Services\LoginService;
use App\Utils\TemplateResponse;
use App\Utils\Router;

$router = new Router();

$router->get(callback: function (): void {
    $user = LoginService::getSession();
    $term = $_GET["q"] ?? "";
    $results = [];

    if (!empty($term)) {
        $repo = new StorageItemRepository();
        $results = $repo->searchByName(userId: $user->getOwner(), term: $term);
    }

    echo TemplateResponse::render(templateLocation: __DIR__ . "/index.twig", data: [
        "results" => $results,
        "term" => $term
    ]);
});

$router->run();
