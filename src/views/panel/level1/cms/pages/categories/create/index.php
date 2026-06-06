<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;

$router = new Router();

function cmsCategorySlugify(string $text): string
{
    $text = trim($text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_\s]/', '', $text);
    $text = preg_replace('/[\s]+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"  => "Create CMS Category",
        "errors" => [],
        "old"    => [
            "name" => "",
            "slug" => "",
            "description" => "",
            "is_active" => 1,
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;
    $ownerId = $sessionUser && $sessionUser->getOwner() ? (int)$sessionUser->getOwner() : SiteContext::businessOwnerId();

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($slug === '') {
        $slug = cmsCategorySlugify($name);
    } else {
        $slug = cmsCategorySlugify($slug);
    }

    $errors = [];

    if ($name === '') {
        $errors[] = "Name is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($categoriesRepository->slugExists($slug)) {
        $errors[] = "That slug already exists. Please choose another one.";
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"  => "Create CMS Category",
            "errors" => $errors,
            "old"    => [
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "is_active" => $isActive,
            ],
        ]);
    }

    $ok = $categoriesRepository->add($categoriesRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "name" => $name,
        "slug" => $slug,
        "description" => $description,
        "is_active" => $isActive,
    ], $authorUserId, $ownerId));

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"  => "Create CMS Category",
            "errors" => ["The category could not be created."],
            "old"    => [
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "is_active" => $isActive,
            ],
        ]);
    }

    
    LocationUtils::redirectInternal("panel/cms/categories");
 
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
