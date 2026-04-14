<?php

use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;

$router = new Router();

function cmsTemplateSlugifyEdit(string $text): string
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

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid template ID.";
        exit;
    }

    $template = $templatesRepository->getOne([
        'id' => $id
    ]);

    if (!$template) {
        echo "Template not found.";
        exit;
    }

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"    => "Edit CMS Template",
        "errors"   => [],
        "template" => $template,
        "old"      => [
            "id" => $template->id,
            "name" => $template->name ?? '',
            "slug" => $template->slug ?? '',
            "description" => $template->description ?? '',
            "template_source" => $template->template_source ?? '',
            "is_active" => (int)($template->is_active ?? 0),
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid template ID.";
        exit;
    }

    $template = $templatesRepository->getOne([
        'id' => $id
    ]);

    if (!$template) {
        echo "Template not found.";
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $templateSource = trim($_POST['template_source'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($slug === '') {
        $slug = cmsTemplateSlugifyEdit($name);
    } else {
        $slug = cmsTemplateSlugifyEdit($slug);
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

    if ($templatesRepository->slugExists($slug, $id)) {
        $errors[] = "That slug already exists. Please choose another one.";
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"    => "Edit CMS Template",
            "errors"   => $errors,
            "template" => $template,
            "old"      => [
                "id" => $id,
                "name" => $name,
                "slug" => $slug,
                "description" => $description,
                "template_source" => $templateSource,
                "is_active" => $isActive,
            ],
        ]);
    }

    $ok = $templatesRepository->update([
        "name" => $name,
        "slug" => $slug,
        "description" => $description,
        "template_source" => $templateSource,
        "is_active" => $isActive,
    ], [
        "id" => $id
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"    => "Edit CMS Template",
            "errors"   => ["The template could not be updated."],
            "template" => $template,
            "old"      => [
                "id" => $id,
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