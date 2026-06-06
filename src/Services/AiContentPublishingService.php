<?php

namespace App\Services;

use App\Repositories\AiContentDraftsRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\Connection;
use App\Repositories\LocationPagesRepository;
use App\Utils\SiteContext;
use Exception;

class AiContentPublishingService
{
    private Connection $db;
    private AiContentDraftsRepository $drafts;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?: new Connection();
        $this->drafts = new AiContentDraftsRepository($this->db);
    }

    public function publish(int $draftId, ?int $userId = null): array
    {
        $draft = $this->drafts->find($draftId);
        if (!$draft) {
            throw new Exception('Draft not found.');
        }

        if ((string)$draft->status !== 'APPROVED') {
            throw new Exception('Only APPROVED drafts can be published.');
        }

        if ((string)$draft->content_type === 'blog_post') {
            return $this->publishBlog($draft, $userId);
        }

        if ((string)$draft->content_type === 'location_page') {
            return $this->publishLocation($draft, $userId);
        }

        throw new Exception('Unsupported AI content type.');
    }

    private function publishBlog(object $draft, ?int $userId = null): array
    {
        $contents = new CmsContentsRepository();
        $contents->db = $this->db;
        $routes = new CmsRoutesRepository();
        $routes->db = $this->db;

        if ($contents->slugExists((string)$draft->slug, (int)$draft->id_owner, (string)$draft->language)) {
            throw new Exception('A CMS post with this slug already exists.');
        }

        $route = $routes->normalizeRoute('blog/' . (string)$draft->slug);
        if ($routes->routeExists($route, (int)$draft->id_owner, (string)$draft->language)) {
            throw new Exception('A public CMS route with this slug already exists.');
        }

        $created = $contents->add($contents->withVnvEventsOrigin([
            'id_owner' => (int)$draft->id_owner,
            'site_key' => (string)$draft->site_key,
            'id_template' => null,
            'id_blog_category' => $this->defaultBlogCategoryId(),
            'type' => 'post',
            'title' => (string)$draft->title,
            'slug' => (string)$draft->slug,
            'language' => (string)$draft->language,
            'content_mode' => 'html',
            'excerpt' => (string)$draft->excerpt,
            'content_json' => null,
            'body_html' => (string)$draft->body_html,
            'meta_title' => (string)$draft->meta_title,
            'meta_description' => (string)$draft->meta_description,
            'meta_keywords' => (string)($draft->meta_keywords ?? ''),
            'canonical_url' => null,
            'robots' => 'index,follow',
            'schema_json' => $draft->schema_json ?: null,
            'featured_image_url' => $draft->featured_image_url ?: null,
            'status' => 'PUBLISHED',
            'is_homepage' => 0,
            'published_at' => date('Y-m-d H:i:s'),
            'last_generated_at' => date('Y-m-d H:i:s'),
        ], $userId ?: (int)($draft->created_by ?? 0) ?: null, (int)$draft->id_owner));

        if (!$created) {
            throw new Exception('The CMS post could not be created.');
        }

        $contentId = $contents->getLastId();
        if ($contentId <= 0) {
            throw new Exception('The CMS post id could not be resolved after publishing.');
        }

        $routes->add($routes->withVnvEventsOrigin([
            'id_owner' => (int)$draft->id_owner,
            'site_key' => (string)$draft->site_key,
            'id_content' => $contentId,
            'route' => $route,
            'route_hash' => md5($route),
            'is_main' => 1,
            'language' => (string)$draft->language,
            'public_php_path' => null,
            'public_twig_path' => null,
            'status' => 'ACTIVE',
            'redirect_to' => null,
        ], $userId ?: (int)($draft->created_by ?? 0) ?: null, (int)$draft->id_owner));

        $this->markVisible((string)$draft->site_key, 'cms_content', $contentId, (int)$draft->id_user_business);
        $this->drafts->markPublished((int)$draft->id, 'cms_content', $contentId);
        $this->regenerateSeoFiles();

        return ['entity_type' => 'cms_content', 'entity_id' => $contentId];
    }

    private function publishLocation(object $draft, ?int $userId = null): array
    {
        $locations = new LocationPagesRepository();
        $locations->db = $this->db;
        if ($locations->slugExists((string)$draft->slug)) {
            throw new Exception('A location page with this slug already exists.');
        }

        $created = $locations->add($locations->withVnvEventsOrigin([
            'id_owner' => (int)$draft->id_owner,
            'site_key' => (string)$draft->site_key,
            'title' => (string)$draft->title,
            'slug' => (string)$draft->slug,
            'category' => (string)($draft->service_name ?: 'Service Area'),
            'template_key' => 'location-ai-service',
            'city' => (string)($draft->city ?: ''),
            'county' => null,
            'state' => (string)($draft->state ?: 'FL'),
            'hero_title' => (string)$draft->title,
            'hero_subtitle' => (string)$draft->excerpt,
            'excerpt' => (string)$draft->excerpt,
            'content_long' => (string)$draft->body_html,
            'primary_keyword' => (string)($draft->topic ?: $draft->title),
            'secondary_keywords' => (string)($draft->meta_keywords ?? ''),
            'hero_image' => $draft->featured_image_url ?: null,
            'gallery_json' => null,
            'faq_json' => $draft->faq_json ?: null,
            'dynamic_blocks_json' => null,
            'meta_title' => (string)$draft->meta_title,
            'meta_description' => (string)$draft->meta_description,
            'meta_keywords' => (string)($draft->meta_keywords ?? ''),
            'og_title' => (string)$draft->meta_title,
            'og_description' => (string)$draft->meta_description,
            'og_image' => $draft->featured_image_url ?: null,
            'canonical_url' => SiteContext::publicBaseUrl() . '/locations/' . trim((string)$draft->slug, '/') . '/',
            'schema_json' => $draft->schema_json ?: null,
            'custom_css' => null,
            'custom_js' => null,
            'is_indexable' => 1,
            'status' => 'PUBLISHED',
            'published_at' => date('Y-m-d H:i:s'),
        ], $userId ?: (int)($draft->created_by ?? 0) ?: null, (int)$draft->id_owner));

        if (!$created) {
            throw new Exception('The location page could not be created.');
        }

        $locationId = $locations->getLastId();
        if ($locationId <= 0) {
            throw new Exception('The location page id could not be resolved after publishing.');
        }

        $this->markVisible((string)$draft->site_key, 'location_page', $locationId, (int)$draft->id_user_business);
        $this->drafts->markPublished((int)$draft->id, 'location_page', $locationId);
        $this->regenerateSeoFiles();

        return ['entity_type' => 'location_page', 'entity_id' => $locationId];
    }

    private function defaultBlogCategoryId(): ?int
    {
        $service = new AiContentAssistantService($this->db);
        $id = $service->defaultBlogCategoryId();

        return $id > 0 ? $id : null;
    }

    private function markVisible(string $siteKey, string $entityType, int $entityId, int $businessId): void
    {
        try {
            $this->db->query("
                INSERT INTO site_visibility
                    (site_key, entity_type, entity_id, id_user_business, is_visible, visibility_status, notes, created_at, updated_at)
                VALUES
                    (:site_key, :entity_type, :entity_id, :id_user_business, 1, 'VISIBLE', 'Published from AI Content review panel after human approval.', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    is_visible = 1,
                    visibility_status = 'VISIBLE',
                    notes = VALUES(notes),
                    updated_at = NOW()
            ");
            $this->db->bind(':site_key', $siteKey);
            $this->db->bind(':entity_type', $entityType);
            $this->db->bind(':entity_id', $entityId);
            $this->db->bind(':id_user_business', $businessId);
            $this->db->execute();
        } catch (\Throwable $e) {
            error_log('Unable to mark AI content visible: ' . $e->getMessage());
        }
    }

    private function regenerateSeoFiles(): void
    {
        try {
            (new SeoFilesGeneratorService())->generate('all');
        } catch (\Throwable $e) {
            error_log('Unable to regenerate SEO files after AI content publish: ' . $e->getMessage());
        }
    }
}
