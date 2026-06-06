<?php

use App\Repositories\LocationPagesRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

function normalizeLocationPageSlug(string $slug): string
{
    $slug = trim(strtolower($slug));
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

function buildDuplicateLocationPageSlug(LocationPagesRepository $repo, string $baseSlug): string
{
    $normalized = normalizeLocationPageSlug($baseSlug);
    if ($normalized === '') {
        $normalized = 'location-page';
    }

    $candidate = normalizeLocationPageSlug($normalized . '-duplicado');
    if ($candidate === '') {
        $candidate = 'location-page-duplicado';
    }

    $counter = 2;
    while ($repo->slugExists($candidate)) {
        $candidate = normalizeLocationPageSlug($normalized . '-duplicado-' . $counter);
        $counter++;
    }

    return $candidate;
}

$router->get(function () {
    $repo = new LocationPagesRepository();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Location Pages",
        "pages" => $repo->getAllForPanel()
    ]);
});

$router->post(function () {
    $repo = new LocationPagesRepository();
    $user = LoginService::getSession();
    $authorUserId = $user ? (int)$user->getId() : null;
    $ownerId = $user && $user->getOwner() ? (int)$user->getOwner() : SiteContext::businessOwnerId();

    $action = trim($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        MessageUtil::setMessage("Invalid page ID.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    $page = $repo->getOne([
        'id' => $id
    ]);

    if (!$page) {
        MessageUtil::setMessage("Location page not found.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'delete') {
        $repo->delete([
            'id' => $id
        ]);

        MessageUtil::setMessage("Location page deleted successfully.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'duplicate') {
        $newSlug = buildDuplicateLocationPageSlug($repo, (string)($page->slug ?? ''));
        $newTitle = trim((string)($page->title ?? '')) . ' (Duplicado)';

        $newId = $repo->add($repo->withVnvEventsOrigin([
            'id_owner' => $page->id_owner ?? $ownerId,
            'title' => trim($newTitle) !== '' ? $newTitle : 'Location Page (Duplicado)',
            'slug' => $newSlug,
            'category' => $page->category ?? 'location',
            'template_key' => $page->template_key ?? 'location-default',
            'city' => $page->city ?? '',
            'county' => $page->county ?? '',
            'state' => $page->state ?? '',
            'hero_title' => $page->hero_title ?? '',
            'hero_subtitle' => $page->hero_subtitle ?? '',
            'excerpt' => $page->excerpt ?? '',
            'content_long' => $page->content_long ?? '',
            'primary_keyword' => $page->primary_keyword ?? '',
            'secondary_keywords' => $page->secondary_keywords ?? '',
            'hero_image' => $page->hero_image ?? '',
            'gallery_json' => $page->gallery_json ?? null,
            'faq_json' => $page->faq_json ?? null,
            'dynamic_blocks_json' => $page->dynamic_blocks_json ?? null,
            'meta_title' => $page->meta_title ?? '',
            'meta_description' => $page->meta_description ?? '',
            'meta_keywords' => $page->meta_keywords ?? '',
            'og_title' => $page->og_title ?? '',
            'og_description' => $page->og_description ?? '',
            'og_image' => $page->og_image ?? '',
            'canonical_url' => SiteContext::publicBaseUrl() . '/locations/' . $newSlug . '/',
            'schema_json' => $page->schema_json ?? null,
            'custom_css' => $page->custom_css ?? '',
            'custom_js' => $page->custom_js ?? '',
            'is_indexable' => (int)($page->is_indexable ?? 0),
            'status' => 'DRAFT',
            'published_at' => null
        ], $authorUserId, (int)($page->id_owner ?? $ownerId)));

        if ($newId) {
            MessageUtil::setMessage("Location page duplicated successfully.");
        } else {
            MessageUtil::setMessage("Could not duplicate location page.");
        }

        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'publish') {
        $repo->update($repo->withVnvEventsOrigin([
            'id_owner' => $page->id_owner ?? $ownerId,
            'status' => 'PUBLISHED',
            'published_at' => date('Y-m-d H:i:s')
        ], $authorUserId, (int)($page->id_owner ?? $ownerId)), [
            'id' => $id
        ]);

        MessageUtil::setMessage("Location page published successfully.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    if ($action === 'draft') {
        $repo->update($repo->withVnvEventsOrigin([
            'id_owner' => $page->id_owner ?? $ownerId,
            'status' => 'DRAFT',
            'published_at' => null
        ], $authorUserId, (int)($page->id_owner ?? $ownerId)), [
            'id' => $id
        ]);

        MessageUtil::setMessage("Location page moved to draft.");
        LocationUtils::redirectInternal("panel/cms/location-pages");
    }

    MessageUtil::setMessage("Invalid action.");
    LocationUtils::redirectInternal("panel/cms/location-pages");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
