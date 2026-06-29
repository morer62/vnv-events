<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsCategoriesRepository;
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

function cmsContentSlugifyEdit(string $text): string
{
    $text = trim($text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_\s]/', '', $text);
    $text = preg_replace('/[\s]+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

function cmsDefaultPageBodyHtmlEdit(): string
{
    return '<section class="container py-5"><div class="row justify-content-center"><div class="col-lg-10"><h1>Page Title</h1><p>Start building your page content here.</p></div></div></section>';
}

function cmsNormalizeContentTypeEdit(string $contentType): string
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

function cmsRouteForContentTypeEdit(string $contentType, string $slug): string
{
    return match (cmsNormalizeContentTypeEdit($contentType)) {
        'location' => 'locations/' . $slug,
        'blog' => 'blog/' . $slug,
        default => $slug,
    };
}

function cmsLegacyTypeForContentTypeEdit(string $contentType): string
{
    return match (cmsNormalizeContentTypeEdit($contentType)) {
        'blog' => 'post',
        default => 'page',
    };
}

function cmsCanonicalUrlForRouteEdit(string $route): string
{
    return SiteContext::publicBaseUrl() . '/' . trim($route, '/') . '/';
}

function cmsShouldUseGeneratedCanonicalEdit(string $canonicalUrl, string $contentType, string $slug): bool
{
    if ($canonicalUrl === '') {
        return true;
    }

    if (cmsNormalizeContentTypeEdit($contentType) === 'page') {
        return false;
    }

    $path = parse_url($canonicalUrl, PHP_URL_PATH);
    return is_string($path) && trim($path, '/') === $slug;
}

$router->get(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid page ID.";
        exit;
    }

    $page = $contentsRepository->getOneWithTemplate($id);

    if (!$page) {
        echo "Page not found.";
        exit;
    }

    $mainRoute = $routesRepository->getMainRouteByContent((int)$page->id, $page->language ?? 'en');
    $templates = $templatesRepository->getActive();
    $categories = $categoriesRepository->getActive();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Edit CMS Page",
        "errors" => [],
        "templates" => $templates,
        "categories" => $categories,
        "page" => $page,
        "old" => [
            "id" => $page->id,
            "id_template" => $page->id_template ?? "",
            "id_cms_category" => $page->id_cms_category ?? "",
            "content_type" => cmsNormalizeContentTypeEdit((string)($page->content_type ?? 'page')),
            "title" => $page->title ?? "",
            "slug" => $page->slug ?? "",
            "excerpt" => $page->excerpt ?? "",
            "status" => $page->status ?? "DRAFT",
            "content_mode" => $page->content_mode ?? "hybrid",
            "body_html" => $page->body_html ?? "",
            "content_json" => $page->content_json ?? "",
            "meta_title" => $page->meta_title ?? "",
            "meta_description" => $page->meta_description ?? "",
            "meta_keywords" => $page->meta_keywords ?? "",
            "canonical_url" => $page->canonical_url ?? "",
            "schema_json" => $page->schema_json ?? "",
            "featured_image_url" => $page->featured_image_url ?? "",
            "og_title" => $page->og_title ?? "",
            "og_description" => $page->og_description ?? "",
            "route" => $mainRoute->route ?? "",
            "robots" => $page->robots ?? "index,follow",
            "is_homepage" => (int)($page->is_homepage ?? 0),
            "published_at" => $page->published_at ?? "",
            "last_generated_at" => $page->last_generated_at ?? "",
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $templates = $templatesRepository->getActive();
    $categories = $categoriesRepository->getActive();
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;
    $ownerId = $sessionUser && $sessionUser->getOwner() ? (int)$sessionUser->getOwner() : SiteContext::businessOwnerId();

    $id                = (int)($_POST['id'] ?? 0);
    $idTemplate        = (int)($_POST['id_template'] ?? 0);
    $idCmsCategory     = (int)($_POST['id_cms_category'] ?? 0);
    $contentType       = cmsNormalizeContentTypeEdit((string)($_POST['content_type'] ?? 'page'));
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

    if ($id <= 0) {
        echo "Invalid page ID.";
        exit;
    }

    $page = $contentsRepository->getOneWithTemplate($id);

    if (!$page) {
        echo "Page not found.";
        exit;
    }

    $mainRoute = $routesRepository->getMainRouteByContent((int)$page->id, $page->language ?? 'en');

    if ($slug === '') {
        $slug = cmsContentSlugifyEdit($title);
    } else {
        $slug = cmsContentSlugifyEdit($slug);
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
        $bodyHtml = !empty($page->body_html) ? $page->body_html : cmsDefaultPageBodyHtmlEdit();
    }

    $route = $manualRoute !== ''
        ? $routesRepository->normalizeRoute($manualRoute)
        : $routesRepository->normalizeRoute(cmsRouteForContentTypeEdit($contentType, $slug));

    if (cmsShouldUseGeneratedCanonicalEdit($canonicalUrl, $contentType, $slug)) {
        $canonicalUrl = cmsCanonicalUrlForRouteEdit($route);
    }

    $errors = [];
    $selectedTemplate = null;
    $selectedCategory = null;

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

    if ($idCmsCategory > 0) {
        $selectedCategory = $categoriesRepository->getOne([
            'id' => $idCmsCategory
        ]);

        if (!$selectedCategory || (int)($selectedCategory->is_active ?? 0) !== 1) {
            $errors[] = "Selected category is invalid.";
        } elseif (!$categoriesRepository->supportsContentType($selectedCategory, $contentType)) {
            $errors[] = "Selected category does not apply to this content type.";
        }
    }

    if ($contentsRepository->slugExists($slug, $ownerId, $page->language ?? 'en', $id)) {
        $errors[] = "That slug already exists.";
    }

    if ($mainRoute) {
        if ($routesRepository->routeExists($route, $ownerId, $page->language ?? 'en', (int)$mainRoute->id)) {
            $errors[] = "That public route already exists.";
        }
    } else {
        if ($routesRepository->routeExists($route, $ownerId, $page->language ?? 'en')) {
            $errors[] = "That public route already exists.";
        }
    }

    $featuredImageUrl = trim($_POST['featured_image_url'] ?? ($page->featured_image_url ?? ''));
    if (FileUtils::hasFile($_FILES, 'featured_image')) {
        try {
            $featuredImageUrl = FileUtils::saveFile($_FILES['featured_image'], 'cms/contents/featured');
        } catch (Exception $e) {
            $errors[] = "Featured image upload failed: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Edit CMS Page",
            "errors" => $errors,
            "templates" => $templates,
            "categories" => $categories,
            "page" => $page,
            "old" => [
                "id" => $id,
                "id_template" => $idTemplate > 0 ? $idTemplate : "",
                "id_cms_category" => $idCmsCategory > 0 ? $idCmsCategory : "",
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
                "published_at" => $page->published_at ?? "",
                "last_generated_at" => $page->last_generated_at ?? "",
            ],
        ]);
    }

    $publishedAt = $page->published_at ?? null;
    if ($status === 'PUBLISHED' && empty($publishedAt)) {
        $publishedAt = date('Y-m-d H:i:s');
    }

    if ($status !== 'PUBLISHED') {
        $publishedAt = null;
    }

    $ok = $contentsRepository->update($contentsRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "id_template" => $idTemplate > 0 ? $idTemplate : null,
        "id_cms_category" => $idCmsCategory > 0 ? $idCmsCategory : null,
        "content_type" => $contentType,
        "type" => cmsLegacyTypeForContentTypeEdit($contentType),
        "title" => $title,
        "slug" => $slug,
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
    ], $authorUserId, $ownerId), [
        "id" => $id
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Edit CMS Page",
            "errors" => ["The page could not be updated."],
            "templates" => $templates,
            "categories" => $categories,
            "page" => $page,
            "old" => [
                "id" => $id,
                "id_template" => $idTemplate > 0 ? $idTemplate : "",
                "id_cms_category" => $idCmsCategory > 0 ? $idCmsCategory : "",
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
                "published_at" => $publishedAt,
                "last_generated_at" => $page->last_generated_at ?? "",
            ],
        ]);
    }

    if ($mainRoute) {
        $routesRepository->update($routesRepository->withVnvEventsOrigin([
            "id_owner" => $ownerId,
            "route" => $route,
            "route_hash" => md5($route),
            "status" => "ACTIVE",
            "redirect_to" => null,
        ], $authorUserId, $ownerId), [
            "id" => (int)$mainRoute->id
        ]);
    } else {
        $routesRepository->add($routesRepository->withVnvEventsOrigin([
            "id_owner" => $ownerId,
            "id_content" => $id,
            "route" => $route,
            "route_hash" => md5($route),
            "is_main" => 1,
            "language" => $page->language ?? 'en',
            "public_php_path" => null,
            "public_twig_path" => null,
            "status" => "ACTIVE",
            "redirect_to" => null,
        ], $authorUserId, $ownerId));
    }

    LocationUtils::redirectInternal("panel/cms/pages/edit?id=" . $id);
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
