<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

function cmsContentSlugify(string $text): string
{
    $text = trim($text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_\s]/', '', $text);
    $text = preg_replace('/[\s]+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

function cmsDefaultPageBodyHtml(): string
{
    return '<section class="container py-5"><div class="row justify-content-center"><div class="col-lg-10"><h1>Page Title</h1><p>Start building your page content here.</p></div></div></section>';
}

function cmsNormalizeContentType(string $contentType): string
{
    $contentType = strtolower(trim($contentType));

    if ($contentType === 'location') {
        return 'location';
    }

    if (in_array($contentType, ['blog', 'guide', 'faq_page', 'comparison', 'case_study'], true)) {
        return 'blog';
    }

    return 'page';
}

function cmsRouteForContentType(string $contentType, string $slug): string
{
    return match (cmsNormalizeContentType($contentType)) {
        'location' => 'locations/' . $slug,
        'blog' => 'blog/' . $slug,
        default => $slug,
    };
}

$router->get(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $templates = $templatesRepository->getActive();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Create CMS Page",
        "errors" => [],
        "templates" => $templates,
        "old" => [
            "id_template" => "",
            "content_type" => "page",
            "title" => "",
            "slug" => "",
            "excerpt" => "",
            "status" => "DRAFT",
            "content_mode" => "hybrid",
            "body_html" => cmsDefaultPageBodyHtml(),
            "content_json" => "",
            "meta_title" => "",
            "meta_description" => "",
            "meta_keywords" => "",
            "canonical_url" => "",
            "schema_json" => "",
            "featured_image_url" => "",
            "og_title" => "",
            "og_description" => "",
            "route" => "",
            "robots" => "index,follow",
            "is_homepage" => 0,
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;
    $ownerId = $sessionUser && $sessionUser->getOwner() ? (int)$sessionUser->getOwner() : SiteContext::businessOwnerId();

    $templates = $templatesRepository->getActive();

    $idTemplate        = (int)($_POST['id_template'] ?? 0);
    $contentType       = cmsNormalizeContentType((string)($_POST['content_type'] ?? 'page'));
    $title             = trim($_POST['title'] ?? '');
    $slug              = trim($_POST['slug'] ?? '');
    $excerpt           = trim($_POST['excerpt'] ?? '');
    $status            = trim($_POST['status'] ?? 'DRAFT');
    $contentMode       = trim($_POST['content_mode'] ?? 'hybrid');
    $bodyHtml          = trim($_POST['body_html'] ?? '');
    $contentJson       = trim($_POST['content_json'] ?? '');
    $metaTitle         = trim($_POST['meta_title'] ?? '');
    $metaDescription   = trim($_POST['meta_description'] ?? '');
    $metaKeywords      = trim($_POST['meta_keywords'] ?? '');
    $canonicalUrl      = trim($_POST['canonical_url'] ?? '');
    $schemaJson        = trim($_POST['schema_json'] ?? '');
    $ogTitle           = trim($_POST['og_title'] ?? '');
    $ogDescription     = trim($_POST['og_description'] ?? '');
    $manualRoute       = trim($_POST['route'] ?? '');
    $robots            = trim($_POST['robots'] ?? 'index,follow');
    $isHomepage        = isset($_POST['is_homepage']) ? 1 : 0;

    if ($slug === '') {
        $slug = cmsContentSlugify($title);
    } else {
        $slug = cmsContentSlugify($slug);
    }

    if (!in_array($status, ['DRAFT', 'PREVIEW', 'GENERATED', 'PUBLISHED', 'ARCHIVED'], true)) {
        $status = 'DRAFT';
    }

    if (!in_array($contentMode, ['structured', 'html', 'hybrid'], true)) {
        $contentMode = 'hybrid';
    }

    if ($metaTitle === '') {
        $metaTitle = $title;
    }

    if ($ogTitle === '') {
        $ogTitle = $title;
    }

    if ($bodyHtml === '') {
        $bodyHtml = cmsDefaultPageBodyHtml();
    }

    $route = $manualRoute !== ''
        ? $routesRepository->normalizeRoute($manualRoute)
        : $routesRepository->normalizeRoute(cmsRouteForContentType($contentType, $slug));

    $errors = [];
    $selectedTemplate = null;

    if ($title === '') {
        $errors[] = "Title is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($contentMode !== 'structured' && $bodyHtml === '') {
        $errors[] = "Body HTML is required for this content mode.";
    }

    if ($contentJson !== '') {
        json_decode($contentJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "Content JSON is invalid.";
        }
    }

    if ($schemaJson !== '') {
        json_decode($schemaJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "Schema JSON is invalid.";
        }
    }

    if ($idTemplate > 0) {
        $selectedTemplate = $templatesRepository->getOne([
            'id' => $idTemplate
        ]);

        if (!$selectedTemplate) {
            $errors[] = "Selected template is invalid.";
        }
    }

    if ($contentsRepository->slugExists($slug, $ownerId, 'en')) {
        $errors[] = "That slug already exists.";
    }

    if ($routesRepository->routeExists($route, $ownerId, 'en')) {
        $errors[] = "That public route already exists.";
    }

    $featuredImageUrl = trim($_POST['featured_image_url'] ?? '');
    if (FileUtils::hasFile($_FILES, 'featured_image')) {
        try {
            $featuredImageUrl = FileUtils::saveFile($_FILES['featured_image'], 'cms/contents/featured');
        } catch (Exception $e) {
            $errors[] = "Featured image upload failed: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Create CMS Page",
            "errors" => $errors,
            "templates" => $templates,
            "old" => [
                "id_template" => $idTemplate > 0 ? $idTemplate : "",
                "content_type" => $contentType,
                "title" => $title,
                "slug" => $slug,
                "excerpt" => $excerpt,
                "status" => $status,
                "content_mode" => $contentMode,
                "body_html" => $bodyHtml,
                "content_json" => $contentJson,
                "meta_title" => $metaTitle,
                "meta_description" => $metaDescription,
                "meta_keywords" => $metaKeywords,
                "canonical_url" => $canonicalUrl,
                "schema_json" => $schemaJson,
                "featured_image_url" => $featuredImageUrl,
                "og_title" => $ogTitle,
                "og_description" => $ogDescription,
                "route" => $route,
                "robots" => $robots,
                "is_homepage" => $isHomepage,
            ],
        ]);
    }

    $publishedAt = $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null;

    $ok = $contentsRepository->add($contentsRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "id_template" => $idTemplate > 0 ? $idTemplate : null,
        "content_type" => $contentType,
        "type" => "page",
        "title" => $title,
        "slug" => $slug,
        "language" => "en",
        "content_mode" => $contentMode,
        "excerpt" => $excerpt,
        "content_json" => $contentJson !== '' ? $contentJson : null,
        "body_html" => $bodyHtml,
        "meta_title" => $metaTitle,
        "meta_description" => $metaDescription,
        "meta_keywords" => $metaKeywords,
        "canonical_url" => $canonicalUrl,
        "robots" => $robots,
        "schema_json" => $schemaJson !== '' ? $schemaJson : null,
        "featured_image_url" => $featuredImageUrl !== '' ? $featuredImageUrl : null,
        "status" => $status,
        "is_homepage" => $isHomepage,
        "published_at" => $publishedAt,
        "last_generated_at" => null,
    ], $authorUserId, $ownerId));

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Create CMS Page",
            "errors" => ["The page could not be created."],
            "templates" => $templates,
            "old" => [
                "id_template" => $idTemplate > 0 ? $idTemplate : "",
                "content_type" => $contentType,
                "title" => $title,
                "slug" => $slug,
                "excerpt" => $excerpt,
                "status" => $status,
                "content_mode" => $contentMode,
                "body_html" => $bodyHtml,
                "content_json" => $contentJson,
                "meta_title" => $metaTitle,
                "meta_description" => $metaDescription,
                "meta_keywords" => $metaKeywords,
                "canonical_url" => $canonicalUrl,
                "schema_json" => $schemaJson,
                "featured_image_url" => $featuredImageUrl,
                "og_title" => $ogTitle,
                "og_description" => $ogDescription,
                "route" => $route,
                "robots" => $robots,
                "is_homepage" => $isHomepage,
            ],
        ]);
    }

    $contentId = $contentsRepository->getLastId();

    $routesRepository->add($routesRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "id_content" => $contentId,
        "route" => $route,
        "route_hash" => md5($route),
        "is_main" => 1,
        "language" => "en",
        "public_php_path" => null,
        "public_twig_path" => null,
        "status" => "ACTIVE",
        "redirect_to" => null,
    ], $authorUserId, $ownerId));

    LocationUtils::redirectInternal("panel/cms/pages");
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
