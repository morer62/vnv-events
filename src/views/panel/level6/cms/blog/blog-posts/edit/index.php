<?php

use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function blogPostSlugifyEdit(string $text): string
{
    $text = trim($text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_\s]/', '', $text);
    $text = preg_replace('/[\s\-]+/', '_', $text);
    $text = preg_replace('/_+/', '_', $text);
    return trim($text, '_');
}

$router->get(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $blogCategoriesRepository = new BlogCategoriesRepository();
    $blogCategoriesRepository->db = $db;

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo "Invalid post ID.";
        exit;
    }

    $post = $contentsRepository->getOneWithTemplate($id);

    if (!$post || ($post->type ?? '') !== 'post') {
        echo "Post not found.";
        exit;
    }

    $mainRoute = $routesRepository->getMainRouteByContent((int)$post->id, $post->language ?? 'en');

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Edit Blog Post",
        "errors" => [],
        "templates" => $templatesRepository->getActive(),
        "categories" => $blogCategoriesRepository->getActive(),
        "post" => $post,
        "old" => [
            "id" => $post->id,
            "id_template" => $post->id_template ?? "",
            "id_blog_category" => $post->id_blog_category ?? "",
            "title" => $post->title ?? "",
            "slug" => $post->slug ?? "",
            "excerpt" => $post->excerpt ?? "",
            "status" => $post->status ?? "DRAFT",
            "content_mode" => $post->content_mode ?? "html",
            "body_html" => $post->body_html ?? "",
            "content_json" => $post->content_json ?? "",
            "meta_title" => $post->meta_title ?? "",
            "meta_description" => $post->meta_description ?? "",
            "meta_keywords" => $post->meta_keywords ?? "",
            "canonical_url" => $post->canonical_url ?? "",
            "schema_json" => $post->schema_json ?? "",
            "featured_image_url" => $post->featured_image_url ?? "",
            "og_title" => $post->og_title ?? "",
            "og_description" => $post->og_description ?? "",
            "route" => $mainRoute->route ?? "",
            "robots" => $post->robots ?? "index,follow",
            "published_at" => $post->published_at ?? "",
            "last_generated_at" => $post->last_generated_at ?? "",
        ],
    ]);
});

$router->post(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $blogCategoriesRepository = new BlogCategoriesRepository();
    $blogCategoriesRepository->db = $db;

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $templates = $templatesRepository->getActive();
    $categories = $blogCategoriesRepository->getActive();

    $id                = (int)($_POST['id'] ?? 0);
    $idTemplate        = (int)($_POST['id_template'] ?? 0);
    $idBlogCategory    = (int)($_POST['id_blog_category'] ?? 0);
    $title             = trim($_POST['title'] ?? '');
    $slug              = trim($_POST['slug'] ?? '');
    $excerpt           = trim($_POST['excerpt'] ?? '');
    $status            = trim($_POST['status'] ?? 'DRAFT');
    $contentMode       = trim($_POST['content_mode'] ?? 'html');
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

    if ($id <= 0) {
        echo "Invalid post ID.";
        exit;
    }

    $post = $contentsRepository->getOneWithTemplate($id);

    if (!$post || ($post->type ?? '') !== 'post') {
        echo "Post not found.";
        exit;
    }

    $mainRoute = $routesRepository->getMainRouteByContent((int)$post->id, $post->language ?? 'en');

    if ($slug === '') {
        $slug = blogPostSlugifyEdit($title);
    } else {
        $slug = blogPostSlugifyEdit($slug);
    }

    if (!in_array($status, ['DRAFT', 'PREVIEW', 'GENERATED', 'PUBLISHED', 'ARCHIVED'], true)) {
        $status = 'DRAFT';
    }

    if (!in_array($contentMode, ['structured', 'html', 'hybrid'], true)) {
        $contentMode = 'html';
    }

    if ($metaTitle === '') {
        $metaTitle = $title;
    }

    if ($ogTitle === '') {
        $ogTitle = $title;
    }

    $route = $manualRoute !== '' ? $routesRepository->normalizeRoute($manualRoute) : $routesRepository->normalizeRoute($slug);

    $errors = [];

    if ($idBlogCategory <= 0) {
        $errors[] = "Blog category is required.";
    }

    if ($title === '') {
        $errors[] = "Title is required.";
    }

    if ($slug === '') {
        $errors[] = "Slug is required.";
    }

    if ($bodyHtml === '') {
        $errors[] = "Article body is required.";
    }

    $selectedCategory = null;
    if ($idBlogCategory > 0) {
        $selectedCategory = $blogCategoriesRepository->getOne(['id' => $idBlogCategory]);
        if (!$selectedCategory) {
            $errors[] = "Selected blog category is invalid.";
        }
    }

    if ($idTemplate > 0) {
        $selectedTemplate = $templatesRepository->getOne(['id' => $idTemplate]);
        if (!$selectedTemplate) {
            $errors[] = "Selected template is invalid.";
        }
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

    if ($contentsRepository->slugExists($slug, null, $post->language ?? 'en', $id)) {
        $errors[] = "That slug already exists.";
    }

    if ($mainRoute) {
        if ($routesRepository->routeExists($route, null, $post->language ?? 'en', (int)$mainRoute->id)) {
            $errors[] = "That public route already exists.";
        }
    } else {
        if ($routesRepository->routeExists($route, null, $post->language ?? 'en')) {
            $errors[] = "That public route already exists.";
        }
    }

    $featuredImageUrl = trim($_POST['featured_image_url'] ?? ($post->featured_image_url ?? ''));
    if (FileUtils::hasFile($_FILES, 'featured_image')) {
        try {
            $featuredImageUrl = FileUtils::saveFile($_FILES['featured_image'], 'blog/posts/featured');
        } catch (Exception $e) {
            $errors[] = "Featured image upload failed: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Edit Blog Post",
            "errors" => $errors,
            "templates" => $templates,
            "categories" => $categories,
            "post" => $post,
            "old" => [
                "id" => $id,
                "id_template" => $idTemplate > 0 ? $idTemplate : "",
                "id_blog_category" => $idBlogCategory > 0 ? $idBlogCategory : "",
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
                "published_at" => $post->published_at ?? "",
                "last_generated_at" => $post->last_generated_at ?? "",
            ],
        ]);
    }

    $publishedAt = $post->published_at ?? null;
    if ($status === 'PUBLISHED' && empty($publishedAt)) {
        $publishedAt = date('Y-m-d H:i:s');
    }

    if ($status !== 'PUBLISHED') {
        $publishedAt = null;
    }

    $ok = $contentsRepository->update([
        "id_template" => $idTemplate > 0 ? $idTemplate : null,
        "id_blog_category" => $idBlogCategory,
        "title" => $title,
        "slug" => $slug,
        "content_mode" => $contentMode,
        "excerpt" => $excerpt,
        "content_json" => $contentJson !== '' ? $contentJson : null,
        "body_html" => $bodyHtml,
        "meta_title" => $metaTitle,
        "meta_description" => $metaDescription,
        "meta_keywords" => $metaKeywords,
        "canonical_url" => $canonicalUrl !== '' ? $canonicalUrl : null,
        "robots" => $robots,
        "schema_json" => $schemaJson !== '' ? $schemaJson : null,
        "featured_image_url" => $featuredImageUrl !== '' ? $featuredImageUrl : null,
        "status" => $status,
        "published_at" => $publishedAt,
    ], [
        "id" => $id
    ]);

    if (!$ok) {
        return TemplateResponse::render(__DIR__ . "/index.twig", [
            "title" => "Edit Blog Post",
            "errors" => ["The blog post could not be updated."],
            "templates" => $templates,
            "categories" => $categories,
            "post" => $post,
            "old" => [
                "id" => $id,
                "id_template" => $idTemplate > 0 ? $idTemplate : "",
                "id_blog_category" => $idBlogCategory > 0 ? $idBlogCategory : "",
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
                "published_at" => $publishedAt,
                "last_generated_at" => $post->last_generated_at ?? "",
            ],
        ]);
    }

    if ($mainRoute) {
        $routesRepository->update([
            "route" => $route,
            "route_hash" => md5($route),
            "status" => "ACTIVE",
            "redirect_to" => null,
        ], [
            "id" => (int)$mainRoute->id
        ]);
    } else {
        $routesRepository->add([
            "id_content" => $id,
            "route" => $route,
            "route_hash" => md5($route),
            "is_main" => 1,
            "language" => $post->language ?? 'en',
            "public_php_path" => null,
            "public_twig_path" => null,
            "status" => "ACTIVE",
            "redirect_to" => null,
        ]);
    }

    LocationUtils::redirectInternal("panel/cms/blog/blog-posts/edit?id=" . $id);
    exit;
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}