<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;

$router = new Router();

function cmsCategorySlugifyEdit(string $text): string
{
    $text = trim($text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_\s]/', '', $text);
    $text = preg_replace('/[\s]+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

$router->get(function () {
    $db = new Connection();

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;
    $ownerId = $sessionUser && $sessionUser->getOwner() ? (int)$sessionUser->getOwner() : SiteContext::businessOwnerId();

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid category ID.";
        exit;
    }

    $category = $categoriesRepository->getOne([
        'id' => $id
    ]);

    if (!$category) {
        echo "Category not found.";
        exit;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"    => "Edit CMS Category",
        "errors"   => [],
        "category" => $category,
        "old"      => [
            "id" => $category->id,
            "name" => $category->name ?? '',
            "slug" => $category->slug ?? '',
            "description" => $category->description ?? '',
            "is_active" => (int)($category->is_active ?? 0),
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid category ID.";
        exit;
    }

    $category = $categoriesRepository->getOne([
        'id' => $id
    ]);

    if (!$category) {
        echo "Category not found.";
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($slug === '') {
        $slug = cmsCategorySlugifyEdit($name);
    } else {
        $slug = cmsCategorySlugifyEdit($slug);
    }

    $errors = [];

    if ($name === '') {
        $errors[] = "Name is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($categoriesRepository->slugExists($slug, $id)) {
        $errors[] = "That slug already exists. Please choose another one.";
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"    => "Edit CMS Category",
            "errors"   => $errors,
            "category" => $category,
            "old"      => [
                "id" => $id,
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "is_active" => $isActive,
            ],
        ]);
    }

    $ok = $categoriesRepository->update($categoriesRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "name" => $name,
        "slug" => $slug,
        "description" => $description,
        "is_active" => $isActive,
    ], $authorUserId, $ownerId), [
        "id" => $id
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"    => "Edit CMS Category",
            "errors"   => ["The category could not be updated."],
            "category" => $category,
            "old"      => [
                "id" => $id,
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
