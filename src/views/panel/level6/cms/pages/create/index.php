<?php

use App\Repositories\CmsCategoriesRepository;
use App\Repositories\CmsPagesRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function cmsPageSlugify(string $text): string
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

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $categories = $categoriesRepository->getActive();
    $templates  = $templatesRepository->getActive();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title"      => "Create CMS Page",
        "errors"     => [],
        "categories" => $categories,
        "templates"  => $templates,
        "old"        => [
            "id_category"       => "",
            "id_template"       => "",
            "title"             => "",
            "slug"              => "",
            "short_description" => "",
            "status"            => "DRAFT",
            "template_source"   => "{% extends \"templates/base.twig\" %}\n\n{% block styles %}\n<style>\n\n</style>\n{% endblock %}\n\n{% block body %}\n<main>\n\n</main>\n{% endblock %}",
            "custom_css"        => "",
            "schema_markup"     => "",
            "custom_head"       => "",
            "meta_title"        => "",
            "meta_description"  => "",
            "meta_keywords"     => "",
            "canonical_url"     => "",
            "og_title"          => "",
            "og_description"    => "",
            "robots_index"      => 1,
            "robots_follow"     => 1,
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $pagesRepository = new CmsPagesRepository();
    $pagesRepository->db = $db;

    $categories = $categoriesRepository->getActive();
    $templates  = $templatesRepository->getActive();

    $idCategory       = (int)($_POST['id_category'] ?? 0);
    $idTemplate       = (int)($_POST['id_template'] ?? 0);
    $title            = trim($_POST['title'] ?? '');
    $slug             = trim($_POST['slug'] ?? '');
    $shortDescription = trim($_POST['short_description'] ?? '');
    $status           = trim($_POST['status'] ?? 'DRAFT');

    $templateSource   = trim($_POST['template_source'] ?? '');
    $customCss        = trim($_POST['custom_css'] ?? '');
    $schemaMarkup     = trim($_POST['schema_markup'] ?? '');
    $customHead       = trim($_POST['custom_head'] ?? '');

    $metaTitle        = trim($_POST['meta_title'] ?? '');
    $metaDescription  = trim($_POST['meta_description'] ?? '');
    $metaKeywords     = trim($_POST['meta_keywords'] ?? '');
    $canonicalUrl     = trim($_POST['canonical_url'] ?? '');
    $ogTitle          = trim($_POST['og_title'] ?? '');
    $ogDescription    = trim($_POST['og_description'] ?? '');

    $robotsIndex      = isset($_POST['robots_index']) ? 1 : 0;
    $robotsFollow     = isset($_POST['robots_follow']) ? 1 : 0;

    if ($slug === '') {
        $slug = cmsPageSlugify($title);
    } else {
        $slug = cmsPageSlugify($slug);
    }

    if (!in_array($status, ['DRAFT', 'PUBLISHED'])) {
        $status = 'DRAFT';
    }

    if ($metaTitle === '') {
        $metaTitle = $title;
    }

    if ($ogTitle === '') {
        $ogTitle = $title;
    }

    $errors = [];

    if ($idCategory <= 0) {
        $errors[] = "Category is required.";
    }

    if ($title === '') {
        $errors[] = "Title is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($templateSource === '') {
        $errors[] = "Template source is required.";
    }

    $category = null;
    if ($idCategory > 0) {
        $category = $categoriesRepository->getOne([
            'id' => $idCategory
        ]);

        if (!$category) {
            $errors[] = "Selected category is invalid.";
        }
    }

    if ($idTemplate > 0) {
        $template = $templatesRepository->getOne([
            'id' => $idTemplate
        ]);

        if (!$template) {
            $errors[] = "Selected template is invalid.";
        }
    }

    if ($idCategory > 0 && $slug !== '' && $pagesRepository->slugExists($idCategory, $slug)) {
        $errors[] = "That slug already exists in the selected category.";
    }

    $thumbnailUrl = '';
    if (FileUtils::hasFile($_FILES, 'thumbnail_image')) {
        try {
            $thumbnailUrl = FileUtils::saveFile($_FILES['thumbnail_image'], 'cms/pages/thumbnails');
        } catch (Exception $e) {
            $errors[] = "Thumbnail upload failed: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"      => "Create CMS Page",
            "errors"     => $errors,
            "categories" => $categories,
            "templates"  => $templates,
            "old"        => [
                "id_category"       => $idCategory,
                "id_template"       => $idTemplate > 0 ? $idTemplate : "",
                "title"             => $title,
                "slug"              => $slug,
                "short_description" => $shortDescription,
                "status"            => $status,
                "template_source"   => $templateSource,
                "custom_css"        => $customCss,
                "schema_markup"     => $schemaMarkup,
                "custom_head"       => $customHead,
                "meta_title"        => $metaTitle,
                "meta_description"  => $metaDescription,
                "meta_keywords"     => $metaKeywords,
                "canonical_url"     => $canonicalUrl,
                "og_title"          => $ogTitle,
                "og_description"    => $ogDescription,
                "robots_index"      => $robotsIndex,
                "robots_follow"     => $robotsFollow,
            ],
        ]);
    }

    $ok = $pagesRepository->add([
        "id_category"       => $idCategory,
        "id_template"       => $idTemplate > 0 ? $idTemplate : null,
        "title"             => $title,
        "slug"              => $slug,
        "short_description" => $shortDescription,
        "status"            => $status,
        "template_source"   => $templateSource,
        "custom_css"        => $customCss,
        "schema_markup"     => $schemaMarkup,
        "custom_head"       => $customHead,
        "meta_title"        => $metaTitle,
        "meta_description"  => $metaDescription,
        "meta_keywords"     => $metaKeywords,
        "meta_thumbnail"    => $thumbnailUrl,
        "canonical_url"     => $canonicalUrl,
        "og_title"          => $ogTitle,
        "og_description"    => $ogDescription,
        "og_image"          => $thumbnailUrl,
        "robots_index"      => $robotsIndex,
        "robots_follow"     => $robotsFollow,
        "published_path"    => null,
        "last_published_at" => null,
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title"      => "Create CMS Page",
            "errors"     => ["The page could not be created."],
            "categories" => $categories,
            "templates"  => $templates,
            "old"        => [
                "id_category"       => $idCategory,
                "id_template"       => $idTemplate > 0 ? $idTemplate : "",
                "title"             => $title,
                "slug"              => $slug,
                "short_description" => $shortDescription,
                "status"            => $status,
                "template_source"   => $templateSource,
                "custom_css"        => $customCss,
                "schema_markup"     => $schemaMarkup,
                "custom_head"       => $customHead,
                "meta_title"        => $metaTitle,
                "meta_description"  => $metaDescription,
                "meta_keywords"     => $metaKeywords,
                "canonical_url"     => $canonicalUrl,
                "og_title"          => $ogTitle,
                "og_description"    => $ogDescription,
                "robots_index"      => $robotsIndex,
                "robots_follow"     => $robotsFollow,
            ],
        ]);
    }

    LocationUtils::redirectInternal("panel/cms/pages");
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}