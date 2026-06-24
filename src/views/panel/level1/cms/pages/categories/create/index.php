<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\SiteContext;
use App\Utils\FileUtils;

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
            "featured_image_url" => "",
            "featured_image_alt" => "",
            "applies_to_pages" => 1,
            "applies_to_blog" => 1,
            "applies_to_locations" => 1,
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
    $featuredImageUrl = trim($_POST['featured_image_url'] ?? '');
    $featuredImageAlt = trim($_POST['featured_image_alt'] ?? '');
    $appliesToPages = isset($_POST['applies_to_pages']) ? 1 : 0;
    $appliesToBlog = isset($_POST['applies_to_blog']) ? 1 : 0;
    $appliesToLocations = isset($_POST['applies_to_locations']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $errors = [];

    if (FileUtils::hasFile($_FILES, 'featured_image')) {
        try {
            $featuredImageUrl = FileUtils::saveFile($_FILES['featured_image'], 'cms/categories/featured');
        } catch (Exception $e) {
            $errors[] = "The featured image could not be uploaded: " . $e->getMessage();
        }
    }

    if ($slug === '') {
        $slug = cmsCategorySlugify($name);
    } else {
        $slug = cmsCategorySlugify($slug);
    }

    if ($name === '') {
        $errors[] = "Name is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($categoriesRepository->slugExists($slug)) {
        $errors[] = "That slug already exists. Please choose another one.";
    }

    if (!$appliesToPages && !$appliesToBlog && !$appliesToLocations) {
        $errors[] = "Select at least one content pillar for this category.";
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"  => "Create CMS Category",
            "errors" => $errors,
            "old"    => [
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "featured_image_url" => $featuredImageUrl,
                "featured_image_alt" => $featuredImageAlt,
                "applies_to_pages" => $appliesToPages,
                "applies_to_blog" => $appliesToBlog,
                "applies_to_locations" => $appliesToLocations,
                "is_active" => $isActive,
            ],
        ]);
    }

    $ok = $categoriesRepository->add($categoriesRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "name" => $name,
        "slug" => $slug,
        "description" => $description,
        "featured_image_url" => $featuredImageUrl !== '' ? $featuredImageUrl : null,
        "featured_image_alt" => $featuredImageAlt !== '' ? $featuredImageAlt : null,
        "applies_to_pages" => $appliesToPages,
        "applies_to_blog" => $appliesToBlog,
        "applies_to_locations" => $appliesToLocations,
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
                "featured_image_url" => $featuredImageUrl,
                "featured_image_alt" => $featuredImageAlt,
                "applies_to_pages" => $appliesToPages,
                "applies_to_blog" => $appliesToBlog,
                "applies_to_locations" => $appliesToLocations,
                "is_active" => $isActive,
            ],
        ]);
    }

    
    LocationUtils::redirectInternal("panel/cms/pages/categories");
 
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
