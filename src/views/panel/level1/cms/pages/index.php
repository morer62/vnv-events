<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsCategoriesRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Services\CmsImageGenerationService;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

$router->post(function () {
    $action = trim((string)($_POST['action'] ?? ''));
    if (!in_array($action, ['publish_generated', 'update_status', 'delete_one', 'bulk_delete', 'bulk_draft', 'bulk_generate_thumbnails'], true)) {
        LocationUtils::redirectInternal('panel/cms/pages');
    }

    $db = new Connection();
    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;
    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;
    $redirect = trim((string)($_POST['redirect'] ?? 'panel/cms/pages'));
    $redirect = $redirect !== '' ? $redirect : 'panel/cms/pages';

    if ($action === 'update_status') {
        $contentId = (int)($_POST['id'] ?? 0);
        $status = strtoupper(trim((string)($_POST['status'] ?? '')));

        if ($contentId <= 0 || !in_array($status, ['DRAFT', 'PUBLISHED'], true)) {
            MessageUtil::setMessage('Invalid status update request.', 'Status not changed', 'warning');
            LocationUtils::redirectInternal($redirect);
        }

        $updateData = [
            'status' => $status,
            'updated_by' => $authorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($status === 'PUBLISHED') {
            $updateData['published_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'DRAFT') {
            $updateData['published_at'] = null;
        }

        $contentsRepository->update($updateData, ['id' => $contentId]);
        MessageUtil::setMessage('Content status updated.');
        LocationUtils::redirectInternal($redirect);
    }

    if ($action === 'delete_one') {
        $contentId = (int)($_POST['id'] ?? 0);
        if ($contentId <= 0) {
            MessageUtil::setMessage('Invalid delete request.', 'Content not deleted', 'warning');
            LocationUtils::redirectInternal($redirect);
        }

        foreach ($routesRepository->getAllByContent($contentId) as $route) {
            $routesRepository->delete(['id' => (int)$route->id]);
        }
        $contentsRepository->delete(['id' => $contentId]);
        MessageUtil::setMessage('Content deleted.');
        LocationUtils::redirectInternal($redirect);
    }

    if (in_array($action, ['bulk_delete', 'bulk_draft', 'bulk_generate_thumbnails'], true)) {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            MessageUtil::setMessage('Select at least one content item.', 'No items selected', 'warning');
            LocationUtils::redirectInternal($redirect);
        }

        $changed = 0;
        foreach ($ids as $id) {
            if ($action === 'bulk_delete') {
                foreach ($routesRepository->getAllByContent($id) as $route) {
                    $routesRepository->delete(['id' => (int)$route->id]);
                }
                if ($contentsRepository->delete(['id' => $id])) {
                    $changed++;
                }
                continue;
            }

            if ($action === 'bulk_generate_thumbnails') {
                if ($changed >= 3) {
                    continue;
                }

                $item = $contentsRepository->getOneWithTemplate($id);
                if (!$item || trim((string)($item->featured_image_url ?? '')) !== '') {
                    continue;
                }

                try {
                    $imageService = new CmsImageGenerationService();
                    $title = trim((string)($item->title ?? 'VNV Events article'));
                    $excerpt = trim(strip_tags((string)($item->excerpt ?? '')));
                    $prompt = 'Unique thumbnail hero photograph for the VNV Events article titled "' . $title . '". '
                        . ($excerpt !== '' ? 'Editorial context: ' . substr($excerpt, 0, 220) . '. ' : '')
                        . 'Hyperrealistic professional event photography, realistic people, premium event space, natural lighting, no text overlay.';
                    $image = $imageService->generateAndUploadWithRetry($prompt, 'cms/generated-thumbnails', '1024x1024', 2);
                    $contentJson = json_decode((string)($item->content_json ?? '{}'), true);
                    $contentJson = is_array($contentJson) ? $contentJson : [];
                    $contentJson['backfilled_thumbnail'] = $image;

                    if ($contentsRepository->update([
                        'featured_image_url' => (string)($image['url'] ?? ''),
                        'content_json' => json_encode($contentJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_by' => $authorUserId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $id])) {
                        $changed++;
                    }
                } catch (\Throwable $thumbnailError) {
                    continue;
                }
                continue;
            }

            if ($contentsRepository->update([
                'status' => 'DRAFT',
                'published_at' => null,
                'updated_by' => $authorUserId,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id])) {
                $changed++;
            }
        }

        MessageUtil::setMessage($changed . ' content item(s) updated.');
        LocationUtils::redirectInternal($redirect);
    }

    $items = $contentsRepository->getAllForPanel('en', SiteContext::siteKey());
    $published = 0;
    foreach ($items as $item) {
        if (strtoupper((string)($item->status ?? '')) !== 'GENERATED') {
            continue;
        }

        $ok = $contentsRepository->update([
            'status' => 'PUBLISHED',
            'published_at' => date('Y-m-d H:i:s'),
            'updated_by' => $authorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int)$item->id]);

        if ($ok) {
            $published++;
        }
    }

    MessageUtil::setMessage($published . ' generated content item(s) published.');
    LocationUtils::redirectInternal('panel/cms/pages?status=PUBLISHED');
});

$router->get(function () {
    $db = new Connection();

    $contentsRepository = new CmsContentsRepository();
    $contentsRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $contentTypes = ['blog', 'location', 'page'];

    $filters = [
        'status' => strtoupper(trim((string)($_GET['status'] ?? ''))),
        'content_type' => strtolower(trim((string)($_GET['content_type'] ?? ''))),
        'category_id' => (int)($_GET['category_id'] ?? 0),
        'q' => trim((string)($_GET['q'] ?? '')),
    ];

    if (!in_array($filters['content_type'], $contentTypes, true)) {
        $filters['content_type'] = 'blog';
    }

    $activeCategories = $categoriesRepository->getActiveForContentType($filters['content_type']);
    if ($filters['category_id'] > 0) {
        $categoryAllowed = false;
        foreach ($activeCategories as $category) {
            if ((int)$category->id === $filters['category_id']) {
                $categoryAllowed = true;
                break;
            }
        }
        if (!$categoryAllowed) {
            $filters['category_id'] = 0;
        }
    }

    $allPages = $contentsRepository->getAllForPanel('en', SiteContext::siteKey());
    $typeCounts = array_fill_keys($contentTypes, 0);
    $categoryCounts = [];
    foreach ($allPages as $page) {
        $normalizedType = normalizeCmsContentType((string)($page->content_type ?? $page->type ?? 'page'));
        $typeCounts[$normalizedType]++;
        if ($normalizedType === $filters['content_type']) {
            $categoryId = (int)($page->id_cms_category ?? 0);
            $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;
        }
    }

    $pages = array_values(array_filter($allPages, static function ($page) use ($filters): bool {
        if ($filters['status'] !== '' && strtoupper((string)($page->status ?? '')) !== $filters['status']) {
            return false;
        }

        $contentType = normalizeCmsContentType((string)($page->content_type ?? $page->type ?? 'page'));

        if ($contentType !== $filters['content_type']) {
            return false;
        }

        if ($filters['category_id'] > 0 && (int)($page->id_cms_category ?? 0) !== $filters['category_id']) {
            return false;
        }

        if ($filters['q'] !== '') {
            $haystack = strtolower(implode(' ', [
                (string)($page->title ?? ''),
                (string)($page->slug ?? ''),
                (string)($page->meta_title ?? ''),
                (string)($page->meta_description ?? ''),
                $contentType,
            ]));

            return str_contains($haystack, strtolower($filters['q']));
        }

        return true;
    }));

    foreach ($pages as $page) {
        $page->content_type = normalizeCmsContentType((string)($page->content_type ?? 'page'));
        $page->main_route = $routesRepository->getMainRouteByContent((int)$page->id, $page->language ?? 'en');
    }

    $generatedCount = count(array_filter($pages, static fn ($page): bool => strtoupper((string)($page->status ?? '')) === 'GENERATED'));

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "CMS Content",
        "pages" => $pages,
        "contentTypes" => $contentTypes,
        "typeCounts" => $typeCounts,
        "activeCategories" => $activeCategories,
        "categoryCounts" => $categoryCounts,
        "filters" => $filters,
        "generatedCount" => $generatedCount,
    ]);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}

function normalizeCmsContentType(string $contentType): string
{
    $contentType = strtolower(trim($contentType));

    if ($contentType === 'location') {
        return 'location';
    }

    if (in_array($contentType, ['blog', 'post', 'guide', 'faq_page', 'comparison', 'case_study'], true)) {
        return 'blog';
    }

    return 'page';
}
