<?php

use App\Repositories\BrandSiteSettingsRepository;
use App\Repositories\Connection;
use App\Services\LoginService;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

function siteScopeRedirect(): void
{
    header('Location: ' . \App\Utils\LocationUtils::pathFor('panel/planner-hub/settings/site-scope'));
    exit;
}

function seedVisibilityRows(Connection $db, string $siteKey, int $ownerId): void
{
    $seedMap = [
        'store_product' => ['table' => 'store_products', 'owner' => true],
        'store_category' => ['table' => 'store_categories', 'owner' => true],
        'cms_content' => ['table' => 'cms_contents', 'owner' => false],
        'location_page' => ['table' => 'cms_location_pages', 'owner' => false],
        'forum_category' => ['table' => 'forum_categories', 'owner' => false],
        'forum_topic' => ['table' => 'forum_topics', 'owner' => false],
    ];

    foreach ($seedMap as $entityType => $meta) {
        $table = $meta['table'];
        $ownerSql = $meta['owner'] ? 'AND src.id_owner = :owner_id' : '';
        $db->query("
            INSERT INTO site_visibility
                (site_key, entity_type, entity_id, id_user_business, is_visible, visibility_status, notes, created_at, updated_at)
            SELECT :site_key, :entity_type, src.id, :owner_id, 0, 'PENDING_REVIEW',
                   'Auto-seeded by Site Scope panel. Confirm before public display.',
                   NOW(), NOW()
            FROM {$table} src
            WHERE src.site_key = :site_key_2
              {$ownerSql}
              AND NOT EXISTS (
                  SELECT 1
                  FROM site_visibility sv
                  WHERE sv.site_key = :site_key_3
                    AND sv.entity_type = :entity_type_2
                    AND sv.entity_id = src.id
              )
        ");
        $db->bind(':site_key', $siteKey);
        $db->bind(':site_key_2', $siteKey);
        $db->bind(':site_key_3', $siteKey);
        $db->bind(':entity_type', $entityType);
        $db->bind(':entity_type_2', $entityType);
        $db->bind(':owner_id', $ownerId);
        $db->execute();
    }
}

function labelVisibilityRows(Connection $db, array $rows): array
{
    foreach ($rows as $row) {
        $label = null;
        try {
            switch ((string)($row->entity_type ?? '')) {
                case 'store_product':
                    $db->query("SELECT name AS label, slug FROM store_products WHERE id = :id LIMIT 1");
                    break;
                case 'store_category':
                    $db->query("SELECT name AS label, slug FROM store_categories WHERE id = :id LIMIT 1");
                    break;
                case 'cms_content':
                    $db->query("SELECT title AS label, slug FROM cms_contents WHERE id = :id LIMIT 1");
                    break;
                case 'location_page':
                    $db->query("SELECT title AS label, slug FROM cms_location_pages WHERE id = :id LIMIT 1");
                    break;
                case 'forum_category':
                    $db->query("SELECT name AS label, slug FROM forum_categories WHERE id = :id LIMIT 1");
                    break;
                case 'forum_topic':
                    $db->query("SELECT title AS label, slug FROM forum_topics WHERE id = :id LIMIT 1");
                    break;
                default:
                    $db->query("SELECT NULL AS label, NULL AS slug");
                    break;
            }

            $db->bind(':id', (int)($row->entity_id ?? 0));
            $data = $db->fetchOne();
            $label = $data->label ?? null;
            $row->entity_slug = $data->slug ?? null;
        } catch (\Throwable $e) {
            $label = null;
        }

        $row->entity_label = $label ?: (($row->entity_type ?? 'record') . ' #' . ($row->entity_id ?? ''));
    }

    return $rows;
}

$router->get(function () {
    $user = LoginService::getSession();
    $siteKey = SiteContext::siteKey();
    $ownerId = SiteContext::businessOwnerId();
    $settings = [];
    $metrics = [];
    $pending = [];
    $warnings = [];

    try {
        $settings = (new BrandSiteSettingsRepository())->allForSite($siteKey);
    } catch (\Throwable $e) {
        $warnings[] = 'brand_site_settings is not available yet. Run the corrective SQL.';
    }

    try {
        $db = new Connection();
        seedVisibilityRows($db, $siteKey, $ownerId);

        foreach ([
            'store_product' => 'store_products',
            'store_category' => 'store_categories',
            'cms_content' => 'cms_contents',
            'location_page' => 'cms_location_pages',
            'forum_category' => 'forum_categories',
            'forum_topic' => 'forum_topics',
        ] as $entityType => $table) {
            $db->query("
                SELECT
                    :entity_type AS entity_type,
                    COUNT(*) AS total_visible,
                    SUM(CASE WHEN sv.visibility_status = 'PENDING_REVIEW' THEN 1 ELSE 0 END) AS pending_review,
                    SUM(CASE WHEN sv.visibility_status = 'VISIBLE' AND sv.is_visible = 1 THEN 1 ELSE 0 END) AS live_visible
                FROM site_visibility sv
                WHERE sv.site_key = :site_key
                  AND sv.entity_type = :entity_type_2
            ");
            $db->bind(':entity_type', $entityType);
            $db->bind(':entity_type_2', $entityType);
            $db->bind(':site_key', $siteKey);
            $row = $db->fetchOne();
            if ($row) {
                $metrics[] = $row;
            }
        }

        $db->query("
            SELECT *
            FROM site_visibility
            WHERE site_key = :site_key
              AND visibility_status = 'PENDING_REVIEW'
            ORDER BY updated_at DESC, created_at DESC, id DESC
            LIMIT 80
        ");
        $db->bind(':site_key', $siteKey);
        $pending = $db->fetchAll() ?: [];
        $pending = labelVisibilityRows($db, $pending);
    } catch (\Throwable $e) {
        $warnings[] = 'site_visibility is not available yet or needs review: ' . $e->getMessage();
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'user' => $user,
        'siteKey' => $siteKey,
        'ownerId' => $ownerId,
        'settings' => $settings,
        'metrics' => $metrics,
        'pending' => $pending,
        'warnings' => $warnings,
    ]);
});

$router->post(function () {
    $action = trim((string)($_POST['action'] ?? 'save_setting'));

    if ($action === 'visibility') {
        $id = (int)($_POST['visibility_id'] ?? 0);
        $target = trim((string)($_POST['target_status'] ?? ''));

        $statusMap = [
            'VISIBLE' => ['visible' => 1, 'message' => 'Record approved for Avomeal public visibility.'],
            'HIDDEN' => ['visible' => 0, 'message' => 'Record hidden from Avomeal public visibility.'],
            'PENDING_REVIEW' => ['visible' => 0, 'message' => 'Record returned to pending review.'],
            'ARCHIVED' => ['visible' => 0, 'message' => 'Record archived from Avomeal public visibility.'],
        ];

        if ($id <= 0 || !isset($statusMap[$target])) {
            MessageUtil::setMessage('Invalid visibility action.', 'error');
            siteScopeRedirect();
        }

        try {
            $db = new Connection();
            $db->query("
                UPDATE site_visibility
                SET is_visible = :is_visible,
                    visibility_status = :status,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                  AND site_key = :site_key
                LIMIT 1
            ");
            $db->bind(':is_visible', $statusMap[$target]['visible']);
            $db->bind(':status', $target);
            $db->bind(':notes', 'Updated manually from Avomeal Site Scope panel.');
            $db->bind(':id', $id);
            $db->bind(':site_key', SiteContext::siteKey());
            $db->execute();
            MessageUtil::setMessage($statusMap[$target]['message'], 'success');
        } catch (\Throwable $e) {
            MessageUtil::setMessage('Unable to update visibility: ' . $e->getMessage(), 'error');
        }

        siteScopeRedirect();
    }

    $key = trim((string)($_POST['setting_key'] ?? ''));
    $value = trim((string)($_POST['setting_value'] ?? ''));

    if ($key === '') {
        MessageUtil::setMessage('Setting key is required.', 'error');
        siteScopeRedirect();
    }

    try {
        (new BrandSiteSettingsRepository())->upsert($key, $value);
        MessageUtil::setMessage('Site setting saved.', 'success');
    } catch (\Throwable $e) {
        MessageUtil::setMessage('Unable to save setting: ' . $e->getMessage(), 'error');
    }

    siteScopeRedirect();
});

$router->run();
