<?php

use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

function cmsTemplateSlugify(string $text): string
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
        "title"  => "Create CMS Template",
        "errors" => [],
        "old"    => [
            "name" => "",
            "slug" => "",
            "description" => "",
            "template_source" => "{% extends \"templates/base.twig\" %}\n\n{% block styles %}\n<style>\n\n</style>\n{% endblock %}\n\n{% block body %}\n<main>\n\n</main>\n{% endblock %}",
            "is_active" => 1,
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $templateSource = trim($_POST['template_source'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($slug === '') {
        $slug = cmsTemplateSlugify($name);
    } else {
        $slug = cmsTemplateSlugify($slug);
    }

    $errors = [];

    if ($name === '') {
        $errors[] = "Name is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($templateSource === '') {
        $errors[] = "Template source is required.";
    }

    if ($templatesRepository->slugExists($slug)) {
        $errors[] = "That slug already exists. Please choose another one.";
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"  => "Create CMS Template",
            "errors" => $errors,
            "old"    => [
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "template_source" => $templateSource,
                "is_active" => $isActive,
            ],
        ]);
    }

    $ok = $templatesRepository->add([
        "name" => $name,
        "slug" => $slug,
        "description" => $description,
        "template_source" => $templateSource,
        "is_active" => $isActive,
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"  => "Create CMS Template",
            "errors" => ["The template could not be created."],
            "old"    => [
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "template_source" => $templateSource,
                "is_active" => $isActive,
            ],
        ]);
    }

 
    LocationUtils::redirectInternal("panel/cms/templates");
    
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}