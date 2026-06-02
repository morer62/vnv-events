<?php

namespace App\Services;

use App\Repositories\AiContentDraftsRepository;
use App\Repositories\AiContentSettingsRepository;
use App\Repositories\BlogCategoriesRepository;
use App\Repositories\CmsContentsRepository;
use App\Repositories\Connection;
use App\Repositories\LocationPagesRepository;
use Exception;
use Throwable;

class AiContentAssistantService
{
    private AiContentDraftsRepository $drafts;
    private AiContentSettingsRepository $settings;
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?: new Connection();
        $this->drafts = new AiContentDraftsRepository($this->db);
        $this->settings = new AiContentSettingsRepository($this->db);
    }

    public function getSettings(string $siteKey): array
    {
        $siteKey = $this->normalizeSiteKey($siteKey);
        $profile = $this->siteProfile($siteKey);

        $defaults = [
            'enabled' => $this->envBool('AI_CONTENT_ENABLED', false) ? '1' : '0',
            'daily_blog_count' => (string)(int)($_ENV['AI_CONTENT_DAILY_BLOG_COUNT'] ?? 1),
            'daily_location_count' => (string)(int)($_ENV['AI_CONTENT_DAILY_LOCATION_COUNT'] ?? 5),
            'auto_publish' => $this->envBool('AI_CONTENT_AUTO_PUBLISH', false) ? '1' : '0',
            'require_approval' => $this->envBool('AI_CONTENT_REQUIRE_APPROVAL', true) ? '1' : '0',
            'default_language' => trim((string)($_ENV['AI_CONTENT_DEFAULT_LANGUAGE'] ?? 'en')),
            'site_key' => $siteKey,
            'brand_name' => $profile['brand_name'],
            'id_owner' => (string)$profile['id_owner'],
            'id_user_business' => (string)$profile['id_user_business'],
            'cloudinary_enabled' => $this->envBool('AI_CONTENT_CLOUDINARY_ENABLED', true) ? '1' : '0',
            'reddit_sources_enabled' => $this->envBool('AI_CONTENT_REDDIT_SOURCES_ENABLED', false) ? '1' : '0',
            'max_pending_drafts' => (string)(int)($_ENV['AI_CONTENT_MAX_PENDING_DRAFTS'] ?? 50),
            'priority_services' => $profile['priority_services'],
            'priority_cities' => $profile['priority_cities'],
            'location_state' => 'FL',
        ];

        foreach ($defaults as $key => $value) {
            $stored = $this->settings->getValue($key, $siteKey);
            if ($stored !== null) {
                $defaults[$key] = $stored;
            }
        }

        return $defaults;
    }

    public function saveSettings(string $siteKey, array $data): void
    {
        if (!$this->settings->tableExists()) {
            throw new Exception('AI content settings table is not installed. Run db/ai_content_assistant_required.sql first.');
        }

        $profile = $this->siteProfile($siteKey);
        $allowed = [
            'enabled',
            'daily_blog_count',
            'daily_location_count',
            'default_language',
            'cloudinary_enabled',
            'reddit_sources_enabled',
            'max_pending_drafts',
            'priority_services',
            'priority_cities',
            'location_state',
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $this->settings->upsert($key, trim((string)$data[$key]), $siteKey, $profile['id_owner'], $profile['id_user_business']);
            }
        }

        $this->settings->upsert('auto_publish', '0', $siteKey, $profile['id_owner'], $profile['id_user_business']);
        $this->settings->upsert('require_approval', '1', $siteKey, $profile['id_owner'], $profile['id_user_business']);
        $this->settings->upsert('site_key', $siteKey, $siteKey, $profile['id_owner'], $profile['id_user_business']);
        $this->settings->upsert('brand_name', $profile['brand_name'], $siteKey, $profile['id_owner'], $profile['id_user_business']);
        $this->settings->upsert('id_owner', (string)$profile['id_owner'], $siteKey, $profile['id_owner'], $profile['id_user_business']);
        $this->settings->upsert('id_user_business', (string)$profile['id_user_business'], $siteKey, $profile['id_owner'], $profile['id_user_business']);
    }

    public function generateDaily(string $siteKey, ?int $userId = null): array
    {
        if (!$this->drafts->tableExists()) {
            throw new Exception('AI content tables are not installed. Run db/ai_content_assistant_required.sql first.');
        }

        $settings = $this->getSettings($siteKey);
        if (!$this->asBool($settings['enabled'] ?? '0')) {
            return ['created' => 0, 'items' => [], 'message' => 'AI content generation is disabled.'];
        }

        $maxPending = max(1, (int)($settings['max_pending_drafts'] ?? 50));
        $pending = $this->drafts->pendingCount($siteKey);
        if ($pending >= $maxPending) {
            return ['created' => 0, 'items' => [], 'message' => 'Max pending drafts reached.'];
        }

        $remaining = $maxPending - $pending;
        $blogCount = min(max(0, (int)($settings['daily_blog_count'] ?? 1)), $remaining);
        $remaining -= $blogCount;
        $locationCount = min(max(0, (int)($settings['daily_location_count'] ?? 5)), $remaining);

        $items = [];
        for ($i = 0; $i < $blogCount; $i++) {
            $items[] = $this->generateOne('blog_post', $settings, $userId);
        }
        for ($i = 0; $i < $locationCount; $i++) {
            $items[] = $this->generateOne('location_page', $settings, $userId);
        }

        return [
            'created' => count(array_filter($items, fn ($item) => (int)($item['id'] ?? 0) > 0)),
            'items' => $items,
            'message' => 'Draft generation completed. All items remain in NEEDS_REVIEW.',
        ];
    }

    public function regenerateDraft(int $draftId, ?int $userId = null): int
    {
        $draft = $this->drafts->find($draftId);
        if (!$draft) {
            throw new Exception('Draft not found.');
        }

        $settings = $this->getSettings((string)$draft->site_key);
        $settings['priority_services'] = (string)($draft->service_name ?: $settings['priority_services']);
        $settings['priority_cities'] = (string)($draft->city ?: $settings['priority_cities']);

        $generated = $this->buildDraftPayload((string)$draft->content_type, $settings, (string)($draft->review_feedback ?? ''));
        $generated['status'] = 'NEEDS_REVIEW';
        $generated['review_feedback'] = null;

        $this->drafts->replaceDraftContent($draftId, $generated);
        return $draftId;
    }

    private function generateOne(string $type, array $settings, ?int $userId): array
    {
        $payload = $this->buildDraftPayload($type, $settings);
        $payload['content_type'] = $type;

        if ($this->isDuplicate($payload, $settings)) {
            $payload = $this->buildDraftPayload($type, $settings, 'Avoid this duplicate slug/topic and choose a different angle: ' . $payload['slug']);
            $payload['content_type'] = $type;
        }

        if ($this->isDuplicate($payload, $settings)) {
            return ['id' => 0, 'type' => $type, 'title' => $payload['title'] ?? '', 'message' => 'Skipped duplicate draft.'];
        }

        $id = $this->drafts->addDraft([
            'id_owner' => (int)$settings['id_owner'],
            'id_user_business' => (int)$settings['id_user_business'],
            'site_key' => (string)$settings['site_key'],
            'brand_name' => (string)$settings['brand_name'],
            'content_type' => $type,
            'status' => 'NEEDS_REVIEW',
            'language' => (string)$settings['default_language'],
            'title' => $payload['title'],
            'slug' => $payload['slug'],
            'topic' => $payload['topic'] ?? $payload['title'],
            'service_name' => $payload['service_name'] ?? null,
            'city' => $payload['city'] ?? null,
            'state' => $payload['state'] ?? ($settings['location_state'] ?? 'FL'),
            'excerpt' => $payload['excerpt'],
            'body_html' => $payload['body_html'],
            'meta_title' => $payload['meta_title'],
            'meta_description' => $payload['meta_description'],
            'meta_keywords' => $payload['meta_keywords'] ?? null,
            'schema_json' => $payload['schema_json'] ?? null,
            'faq_json' => $payload['faq_json'] ?? null,
            'thumbnail_prompt' => $payload['thumbnail_prompt'] ?? null,
            'featured_image_url' => $payload['featured_image_url'] ?? null,
            'source_notes_json' => $payload['source_notes_json'] ?? null,
            'internal_links_json' => $payload['internal_links_json'] ?? null,
            'ai_model' => $payload['ai_model'],
            'ai_prompt_hash' => $payload['ai_prompt_hash'],
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'type' => $type, 'title' => $payload['title'], 'slug' => $payload['slug']];
    }

    private function buildDraftPayload(string $type, array $settings, string $extraInstruction = ''): array
    {
        $apiKey = trim((string)($_ENV['OPENAI_TOKEN'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('OPENAI_TOKEN is not configured. No AI draft was created.');
        }

        $model = trim((string)($_ENV['AI_CONTENT_MODEL'] ?? 'gpt-4o-mini'));
        $prompt = $this->buildPrompt($type, $settings, $extraInstruction);
        $response = $this->callOpenAI($apiKey, $model, $prompt);
        $data = $this->decodeJsonResponse($response);

        foreach (['title', 'slug', 'excerpt', 'body_html', 'meta_title', 'meta_description'] as $required) {
            if (trim((string)($data[$required] ?? '')) === '') {
                throw new Exception("OpenAI response is missing {$required}.");
            }
        }

        $data['slug'] = $this->slugify((string)$data['slug']);
        $data['ai_model'] = $model;
        $data['ai_prompt_hash'] = hash('sha256', $prompt);

        foreach (['schema_json', 'faq_json', 'source_notes_json', 'internal_links_json'] as $jsonField) {
            if (isset($data[$jsonField]) && is_array($data[$jsonField])) {
                $data[$jsonField] = json_encode($data[$jsonField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $data;
    }

    private function buildPrompt(string $type, array $settings, string $extraInstruction = ''): string
    {
        $services = $settings['priority_services'] ?? '';
        $cities = $settings['priority_cities'] ?? '';
        $brand = $settings['brand_name'] ?? 'VNV Events';
        $language = $settings['default_language'] ?? 'en';
        $state = $settings['location_state'] ?? 'FL';
        $siteKey = $settings['site_key'] ?? '';
        $contentKind = $type === 'location_page' ? 'location landing page' : 'blog post';

        return <<<PROMPT
Create one {$contentKind} draft for {$brand}.

Return only valid JSON. Required keys:
title, slug, topic, service_name, city, state, excerpt, body_html, meta_title, meta_description, meta_keywords, schema_json, faq_json, thumbnail_prompt, source_notes_json, internal_links_json.

Rules:
- Language: {$language}.
- Site key: {$siteKey}.
- Priority services: {$services}.
- Priority cities: {$cities}.
- Default state: {$state}.
- Status is human review only; never claim this is published.
- Avoid generic AI phrases and filler.
- Do not invent offices, addresses, reviews, ratings, guarantees, licenses, certifications, awards, pricing, or staff names.
- For location pages, use JSON-LD Service with areaServed. Do not create a fake LocalBusiness for each city.
- Make schema_json valid JSON data, not a string of HTML.
- body_html must be clean HTML with headings, paragraphs, lists, and FAQ section when appropriate.
- Include practical internal link suggestions as JSON.
- Include source_notes_json explaining what would need human verification.
{$extraInstruction}
PROMPT;
    }

    private function callOpenAI(string $apiKey, string $model, string $prompt): string
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a careful SEO content assistant for a human-reviewed CMS.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new Exception('OpenAI draft generation failed: ' . ($error ?: $response));
        }

        return $response;
    }

    private function decodeJsonResponse(string $response): array
    {
        $outer = json_decode($response, true);
        $content = $outer['choices'][0]['message']['content'] ?? '';
        $data = json_decode((string)$content, true);

        if (!is_array($data)) {
            throw new Exception('OpenAI returned invalid JSON content.');
        }

        return $data;
    }

    private function isDuplicate(array $payload, array $settings): bool
    {
        $siteKey = (string)$settings['site_key'];
        $type = (string)($payload['content_type'] ?? '');
        $slug = $this->slugify((string)($payload['slug'] ?? ''));
        $contentType = $type !== '' ? $type : ((string)($payload['city'] ?? '') !== '' ? 'location_page' : 'blog_post');

        if ($slug === '' || $this->drafts->slugExists($slug, $contentType, $siteKey)) {
            return true;
        }

        if ($contentType === 'blog_post') {
            $contents = new CmsContentsRepository();
            $contents->db = $this->db;
            return $contents->slugExists($slug, (int)$settings['id_owner'], (string)$settings['default_language']);
        }

        $locations = new LocationPagesRepository();
        if ($locations->slugExists($slug)) {
            return true;
        }

        $service = trim((string)($payload['service_name'] ?? ''));
        $city = trim((string)($payload['city'] ?? ''));
        return $service !== '' && $city !== '' && $this->drafts->locationTopicExists($service, $city, 'location_page', $siteKey);
    }

    public function defaultBlogCategoryId(): int
    {
        try {
            $repo = new BlogCategoriesRepository();
            $repo->db = $this->db;
            $categories = $repo->getActive();
            return (int)($categories[0]->id ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function siteProfile(string $siteKey): array
    {
        $siteKey = $this->normalizeSiteKey($siteKey);

        if ($siteKey === 'avomeal') {
            return [
                'site_key' => 'avomeal',
                'brand_name' => 'Avomeal',
                'id_owner' => 2,
                'id_user_business' => 2,
                'priority_services' => 'meal preps, holiday menus, party boxes, prepared meals, appetizers, desserts',
                'priority_cities' => 'Miami, Doral, Fort Lauderdale, Hollywood, Weston, Pembroke Pines, Coral Gables',
            ];
        }

        return [
            'site_key' => $siteKey,
            'brand_name' => 'VNV Events',
            'id_owner' => (int)($_ENV['AI_CONTENT_OWNER_ID'] ?? 2),
            'id_user_business' => (int)($_ENV['AI_CONTENT_ID_USER_BUSINESS'] ?? 2),
            'priority_services' => 'wedding planning, corporate events, social events, event rentals, catering coordination',
            'priority_cities' => 'Miami, Doral, Fort Lauderdale, Hollywood, Weston, Pembroke Pines, Coral Gables',
        ];
    }

    private function envBool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $_ENV)) {
            return $default;
        }

        return $this->asBool($_ENV[$key]);
    }

    private function asBool(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeSiteKey(?string $siteKey): string
    {
        $siteKey = trim((string)($siteKey ?? ''));
        return $siteKey !== '' ? strtolower($siteKey) : strtolower((string)($_ENV['AI_CONTENT_SITE_KEY'] ?? 'vnv_events'));
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s_-]/', '', $text) ?: '';
        $text = preg_replace('/[\s_-]+/', '-', $text) ?: '';

        return trim($text, '-');
    }
}
