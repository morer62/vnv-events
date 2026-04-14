<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\Connection;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function blogCategorySlugify(string $text): string
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
        "title" => "Create Blog Category",
        "errors" => [],
        "old" => [
            "name" => "",
            "slug" => "",
            "description" => "",
            "meta_title" => "",
            "meta_description" => "",
            "meta_keywords" => "",
            "featured_image_url" => "",
            "schema_json" => "",
            "status" => "ACTIVE",
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $categoriesRepository = new BlogCategoriesRepository();
    $categoriesRepository->db = $db;

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $metaTitle = trim($_POST['meta_title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $metaKeywords = trim($_POST['meta_keywords'] ?? '');
    $featuredImageUrl = trim($_POST['featured_image_url'] ?? '');
    $schemaJson = trim($_POST['schema_json'] ?? '');
    $status = trim($_POST['status'] ?? 'ACTIVE');

    if ($slug === '') {
        $slug = blogCategorySlugify($name);
    } else {
        $slug = blogCategorySlugify($slug);
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

    if ($categoriesRepository->slugExists($slug)) {
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
            "title" => "Create Blog Category",
            "errors" => $errors,
            "old" => [
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

    $ok = $categoriesRepository->add([
        "name" => $name,
        "slug" => $slug,
        "description" => $description !== '' ? $description : null,
        "meta_title" => $metaTitle !== '' ? $metaTitle : null,
        "meta_description" => $metaDescription !== '' ? $metaDescription : null,
        "meta_keywords" => $metaKeywords !== '' ? $metaKeywords : null,
        "featured_image_url" => $featuredImageUrl !== '' ? $featuredImageUrl : null,
        "schema_json" => $schemaJson !== '' ? $schemaJson : null,
        "status" => $status,
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Create Blog Category",
            "errors" => ["The category could not be created."],
            "old" => [
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

    LocationUtils::redirectInternal("panel/cms/blog/blog-categories");
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}