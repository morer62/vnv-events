<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

function blogCategorySlugifyEdit(string $text): string
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

    $categoriesRepository = new BlogCategoriesRepository();
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
        "title" => "Edit Blog Category",
        "errors" => [],
        "category" => $category,
        "old" => [
            "id" => $category->id,
            "name" => $category->name ?? "",
            "slug" => $category->slug ?? "",
            "description" => $category->description ?? "",
            "meta_title" => $category->meta_title ?? "",
            "meta_description" => $category->meta_description ?? "",
            "meta_keywords" => $category->meta_keywords ?? "",
            "featured_image_url" => $category->featured_image_url ?? "",
            "schema_json" => $category->schema_json ?? "",
            "status" => $category->status ?? "ACTIVE",
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $categoriesRepository = new BlogCategoriesRepository();
    $categoriesRepository->db = $db;

    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $metaTitle = trim($_POST['meta_title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $metaKeywords = trim($_POST['meta_keywords'] ?? '');
    $featuredImageUrl = trim($_POST['featured_image_url'] ?? '');
    $schemaJson = trim($_POST['schema_json'] ?? '');
    $status = trim($_POST['status'] ?? 'ACTIVE');

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

    if ($slug === '') {
        $slug = blogCategorySlugifyEdit($name);
    } else {
        $slug = blogCategorySlugifyEdit($slug);
    }

    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        $status = 'ACTIVE';
    }

    if ($metaTitle === '') {
        $metaTitle = $name;
    }

    $errors = [];

    if ($name === '') {
        $errors[] = "Name is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($schemaJson !== '') {
        json_decode($schemaJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "Schema JSON is invalid.";
        }
    }

    if ($categoriesRepository->slugExists($slug, $id)) {
        $errors[] = "That slug already exists.";
    }

    if (FileUtils::hasFile($_FILES, 'featured_image')) {
        try {
            $featuredImageUrl = FileUtils::saveFile($_FILES['featured_image'], 'blog/categories');
        } catch (Exception $e) {
            $errors[] = "Featured image upload failed: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Edit Blog Category",
            "errors" => $errors,
            "category" => $category,
            "old" => [
                "id" => $id,
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "meta_title" => $metaTitle,
                "meta_description" => $metaDescription,
                "meta_keywords" => $metaKeywords,
                "featured_image_url" => $featuredImageUrl,
                "schema_json" => $schemaJson,
                "status" => $status,
            ],
        ]);
    }

    $ok = $categoriesRepository->update($categoriesRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "name" => $name,
        "slug" => $slug,
        "description" => $description !== '' ? $description : null,
        "meta_title" => $metaTitle !== '' ? $metaTitle : null,
        "meta_description" => $metaDescription !== '' ? $metaDescription : null,
        "meta_keywords" => $metaKeywords !== '' ? $metaKeywords : null,
        "featured_image_url" => $featuredImageUrl !== '' ? $featuredImageUrl : null,
        "schema_json" => $schemaJson !== '' ? $schemaJson : null,
        "status" => $status,
    ], $authorUserId, $ownerId), [
        "id" => $id
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Edit Blog Category",
            "errors" => ["The category could not be updated."],
            "category" => $category,
            "old" => [
                "id" => $id,
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "meta_title" => $metaTitle,
                "meta_description" => $metaDescription,
                "meta_keywords" => $metaKeywords,
                "featured_image_url" => $featuredImageUrl,
                "schema_json" => $schemaJson,
                "status" => $status,
            ],
        ]);
    }

    LocationUtils::redirectInternal("panel/cms/blog/blog-categories/edit?id=" . $id);
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
