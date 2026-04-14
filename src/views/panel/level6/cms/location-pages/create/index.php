<?php

use App\Repositories\LocationPagesRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "page" => null
    ]);
});

$router->post(function () {
    $repo = new LocationPagesRepository();
    $user = LoginService::getSession();

    $title = trim($_POST['title'] ?? '');
    $slug = trim(strtolower($_POST['slug'] ?? ''));
    $category = trim($_POST['category'] ?? 'location');
    $templateKey = trim($_POST['template_key'] ?? 'location-default');
    $city = trim($_POST['city'] ?? '');
    $county = trim($_POST['county'] ?? '');
    $state = trim($_POST['state'] ?? 'Florida');
    $heroTitle = trim($_POST['hero_title'] ?? '');
    $heroSubtitle = trim($_POST['hero_subtitle'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $contentLong = trim($_POST['content_long'] ?? '');
    $primaryKeyword = trim($_POST['primary_keyword'] ?? '');
    $secondaryKeywords = trim($_POST['secondary_keywords'] ?? '');
    $heroImage = trim($_POST['hero_image'] ?? '');
    $faqJson = trim($_POST['faq_json'] ?? '');
    $dynamicBlocksJson = trim($_POST['dynamic_blocks_json'] ?? '');
    $metaTitle = trim($_POST['meta_title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $metaKeywords = trim($_POST['meta_keywords'] ?? '');
    $ogTitle = trim($_POST['og_title'] ?? '');
    $ogDescription = trim($_POST['og_description'] ?? '');
    $ogImage = trim($_POST['og_image'] ?? '');
    $canonicalUrl = trim($_POST['canonical_url'] ?? '');
    $schemaJson = trim($_POST['schema_json'] ?? '');
    $customCss = trim($_POST['custom_css'] ?? '');
    $customJs = trim($_POST['custom_js'] ?? '');
    $status = trim($_POST['status'] ?? 'DRAFT');
    $isIndexable = isset($_POST['is_indexable']) ? 1 : 0;

    if ($title === '' || $slug === '') {
        MessageUtil::setMessage("Title and slug are required.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages/create");
    }

    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        MessageUtil::setMessage("Invalid slug.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages/create");
    }

    if ($repo->slugExists($slug)) {
        MessageUtil::setMessage("That slug already exists.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages/create");
    }

    if ($faqJson !== '' && json_decode($faqJson, true) === null) {
        MessageUtil::setMessage("FAQ JSON is invalid.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages/create");
    }

    if ($dynamicBlocksJson !== '' && json_decode($dynamicBlocksJson, true) === null) {
        MessageUtil::setMessage("Dynamic Blocks JSON is invalid.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages/create");
    }

    if ($schemaJson !== '' && json_decode($schemaJson, true) === null) {
        MessageUtil::setMessage("Schema JSON is invalid.");
        LocationUtils::redirectInternal("panel/level6/cms/location-pages/create");
    }

    $repo->add([
        'id_owner' => $user ? $user->getOwner() : null,
        'title' => $title,
        'slug' => $slug,
        'category' => $category,
        'template_key' => $templateKey,
        'city' => $city,
        'county' => $county,
        'state' => $state,
        'hero_title' => $heroTitle,
        'hero_subtitle' => $heroSubtitle,
        'excerpt' => $excerpt,
        'content_long' => $contentLong,
        'primary_keyword' => $primaryKeyword,
        'secondary_keywords' => $secondaryKeywords,
        'hero_image' => $heroImage,
        'faq_json' => $faqJson ?: null,
        'dynamic_blocks_json' => $dynamicBlocksJson ?: null,
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'meta_keywords' => $metaKeywords,
        'og_title' => $ogTitle,
        'og_description' => $ogDescription,
        'og_image' => $ogImage,
        'canonical_url' => $canonicalUrl,
        'schema_json' => $schemaJson ?: null,
        'custom_css' => $customCss,
        'custom_js' => $customJs,
        'is_indexable' => $isIndexable,
        'status' => $status,
        'published_at' => $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null,
    ]);

    MessageUtil::setMessage("Location page created successfully.");
    LocationUtils::redirectInternal("panel/level6/cms/location-pages");
});

$router->run();