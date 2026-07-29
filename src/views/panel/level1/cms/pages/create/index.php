<?php

use App\Repositories\CmsContentsRepository;
use App\Repositories\CmsCategoriesRepository;
use App\Repositories\CmsRoutesRepository;
use App\Repositories\CmsTemplatesRepository;
use App\Repositories\Connection;
use App\Repositories\AiAgentsRepository;
use App\Services\AiAgentRegistry;
use App\Services\CmsImageGenerationService;
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

function cmsLegacyTypeForContentType(string $contentType): string
{
    return match (cmsNormalizeContentType($contentType)) {
        'blog' => 'post',
        default => 'page',
    };
}

function cmsCanonicalUrlForRoute(string $route): string
{
    return SiteContext::publicBaseUrl() . '/' . trim($route, '/') . '/';
}

function cmsShouldUseGeneratedCanonical(string $canonicalUrl, string $contentType, string $slug): bool
{
    if ($canonicalUrl === '') {
        return true;
    }

    if (cmsNormalizeContentType($contentType) === 'page') {
        return false;
    }

    $path = parse_url($canonicalUrl, PHP_URL_PATH);
    return is_string($path) && trim($path, '/') === $slug;
}

function cmsJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cmsCallOpenAiJson(string $system, array $payload, int $timeout = 120): array
{
    $apiKey = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
    if ($apiKey === '') {
        throw new Exception('OPENAI_TOKEN is not configured.');
    }

    $model = trim((string)($_ENV['AI_CONTENT_MODEL'] ?? $_ENV['OPENAI_TEXT_MODEL'] ?? 'gpt-4o-mini'));
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'temperature' => 0.72,
            'response_format' => ['type' => 'json_object'],
        ]),
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new Exception('AI request failed: ' . ($error ?: $response));
    }

    $outer = json_decode((string)$response, true);
    $content = $outer['choices'][0]['message']['content'] ?? '';
    $decoded = json_decode((string)$content, true);
    if (!is_array($decoded)) {
        throw new Exception('AI returned invalid JSON.');
    }

    return $decoded;
}

function cmsFetchRemoteReference(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $ch = curl_init('https://duckduckgo.com/html/?q=' . rawurlencode($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 VNV Events CMS',
        CURLOPT_TIMEOUT => 12,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!is_string($html) || $html === '') {
        return null;
    }

    if (!preg_match('/<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $match)) {
        return null;
    }

    $href = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $title = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
    $parts = parse_url($href);
    parse_str($parts['query'] ?? '', $queryParts);
    $resolvedUrl = isset($queryParts['uddg']) ? (string)$queryParts['uddg'] : $href;

    return [
        'title' => $title !== '' ? $title : $resolvedUrl,
        'url' => $resolvedUrl,
    ];
}

function cmsTemplateIdForType(array $templates, string $contentType, int $preferredId): ?int
{
    if ($preferredId > 0) {
        return $preferredId;
    }

    $wanted = match (cmsNormalizeContentType($contentType)) {
        'location' => ['location', 'local-location-page'],
        'blog' => ['post', 'blog', 'editorial-guide'],
        default => ['landing', 'page', 'service-landing'],
    };

    foreach ($templates as $template) {
        $type = strtolower((string)($template->type ?? ''));
        $key = strtolower((string)($template->template_key ?? ''));
        if (in_array($type, $wanted, true) || in_array($key, $wanted, true)) {
            return (int)$template->id;
        }
    }

    return null;
}

function cmsBuildAiArticleHtml(array $article, int $imageCount, array $generatedImages = []): string
{
    $title = htmlspecialchars((string)($article['title'] ?? 'VNV Events Article'), ENT_QUOTES, 'UTF-8');
    $body = trim((string)($article['body_html'] ?? ''));
    $imagePrompts = $article['image_prompts'] ?? [];
    if (!is_array($imagePrompts)) {
        $imagePrompts = [];
    }

    if ($body === '') {
        $body = '<p>' . htmlspecialchars((string)($article['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $mediaBlock = '';
    if ($generatedImages !== []) {
        foreach ($generatedImages as $image) {
            $url = htmlspecialchars((string)($image['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($url === '') {
                continue;
            }
            $alt = htmlspecialchars((string)($image['prompt'] ?? $title), ENT_QUOTES, 'UTF-8');
            $mediaBlock .= '<figure><img src="' . $url . '" alt="' . $alt . '" loading="lazy"><figcaption>' . $alt . '</figcaption></figure>';
        }
        if ($mediaBlock !== '') {
            $mediaBlock = '<section><h2>Visuals</h2>' . $mediaBlock . '</section>';
        }
    } else {
        foreach (array_slice($imagePrompts, 0, max(0, $imageCount)) as $index => $prompt) {
            $mediaBlock .= '<li>Image ' . ($index + 1) . ': ' . htmlspecialchars((string)$prompt, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        if ($mediaBlock !== '') {
            $mediaBlock = '<section><h2>Image plan</h2><ul>' . $mediaBlock . '</ul></section>';
        }
    }

    return '<article class="cms-ai-article"><header><h1>' . $title . '</h1></header>' . $body . $mediaBlock . '</article>';
}

function cmsAiArticleImagePrompts(array $article, array $idea, string $title, int $supportingImageCount, int $batchIndex = 0, string $batchToken = ''): array
{
    if ($supportingImageCount <= 0) {
        return [];
    }

    $visualDirections = [
        'wide establishing composition with layered foreground and background activity',
        'intimate documentary composition focused on candid human interaction',
        'editorial detail composition featuring hands, materials and event craftsmanship',
        'architectural composition emphasizing venue scale, lighting and spatial design',
        'dynamic side-angle composition capturing service professionals in action',
        'elevated composition showing a carefully arranged event environment',
    ];
    $directionIndex = abs(crc32(strtolower($title) . '|' . $batchToken . '|' . $batchIndex)) % count($visualDirections);
    $visualDirection = $visualDirections[$directionIndex];
    $uniqueIdentity = substr(hash('sha256', strtolower($title) . '|' . $batchToken . '|' . $batchIndex), 0, 12);
    $angle = trim((string)($idea['angle'] ?? $idea['excerpt'] ?? $article['excerpt'] ?? ''));
    $thumbnailPrompt = 'Unique thumbnail hero photograph for the article titled "' . $title . '". '
        . ($angle !== '' ? 'Editorial angle: ' . $angle . '. ' : '')
        . 'Visual direction: ' . $visualDirection . '. '
        . 'Create a distinct VNV Events corporate/event service scene that is clearly different in subject, camera angle, venue, color palette and composition from every other article image in this batch. '
        . 'Creative identity ' . $uniqueIdentity . ' (use only to enforce variation; never render it as text). '
        . 'Hyperrealistic professional event photography, no text overlay.';

    $prompts = [$thumbnailPrompt];
    $seen = [strtolower(preg_replace('/\s+/', ' ', $thumbnailPrompt)) => true];
    $rawPrompts = $article['image_prompts'] ?? [];
    if (!is_array($rawPrompts)) {
        $rawPrompts = [];
    }

    foreach ($rawPrompts as $index => $prompt) {
        $prompt = trim((string)$prompt);
        if ($prompt === '') {
            continue;
        }

        $supportDirection = $visualDirections[($directionIndex + $index + 1) % count($visualDirections)];
        $prompt = 'Supporting image ' . ($index + 1) . ' for the article titled "' . $title . '": ' . $prompt
            . ' Use this separate visual direction: ' . $supportDirection . '. Make the subject, venue, camera angle and color palette specific to this article and visibly different from the thumbnail and other supporting images. Creative identity ' . $uniqueIdentity . '-' . ($index + 1) . ' must not appear as text.';
        $key = strtolower(preg_replace('/\s+/', ' ', $prompt));
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $prompts[] = $prompt;
        if (count($prompts) >= ($supportingImageCount + 1)) {
            break;
        }
    }

    while (count($prompts) < ($supportingImageCount + 1)) {
        $fallbackIndex = count($prompts);
        $supportDirection = $visualDirections[($directionIndex + $fallbackIndex) % count($visualDirections)];
        $prompts[] = 'Supporting event photograph for the article titled "' . $title . '", ' . $supportDirection . ', creative identity ' . $uniqueIdentity . '-' . $fallbackIndex . ' must not appear as text, realistic VNV Events service context, hyperrealistic professional photography, no text overlay.';
    }

    return array_slice($prompts, 0, $supportingImageCount + 1);
}

function cmsExtendAiRuntime(): void
{
    @ini_set('max_execution_time', '900');
    @ini_set('default_socket_timeout', '900');
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }
}

function cmsServiceInternalLinkCandidates(): array
{
    return [
        ['id' => 'service-event-planners', 'title' => 'Event Planner & Day Coordinator', 'type' => 'service', 'route' => '/event-planners', 'status' => 'PUBLISHED'],
        ['id' => 'service-catering', 'title' => 'VNV Gourmet Catering', 'type' => 'service', 'route' => '/vnv-gourmet', 'status' => 'PUBLISHED'],
        ['id' => 'service-floral', 'title' => 'Floral Decorations', 'type' => 'service', 'route' => '/vnv-deco-flowers', 'status' => 'PUBLISHED'],
        ['id' => 'service-dj-karaoke', 'title' => 'DJ and Karaoke Services', 'type' => 'service', 'route' => '/vnv-live', 'status' => 'PUBLISHED'],
        ['id' => 'service-photo-video', 'title' => 'Photography & Videography', 'type' => 'service', 'route' => '/photo-booth-rental-video-photography-services-in-south-florida', 'status' => 'PUBLISHED'],
        ['id' => 'service-rentals', 'title' => 'Party Tents & Event Rentals', 'type' => 'service', 'route' => '/party-tents-and-event-rental-services-in-south-florida', 'status' => 'PUBLISHED'],
        ['id' => 'service-production', 'title' => 'Event Production', 'type' => 'service', 'route' => '/event-production', 'status' => 'PUBLISHED'],
        ['id' => 'service-staffing', 'title' => 'Event Staffing', 'type' => 'service', 'route' => '/event-staffing/', 'status' => 'PUBLISHED'],
        ['id' => 'service-corporate-events', 'title' => 'Corporate Events', 'type' => 'service', 'route' => '/corporate-events', 'status' => 'PUBLISHED'],
    ];
}

$router->get(function () {
    $db = new Connection();

    $templatesRepository = new CmsTemplatesRepository();
    $templatesRepository->db = $db;

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $templates = $templatesRepository->getActive();
    $categories = $categoriesRepository->getActive();
    $internalLinks = cmsServiceInternalLinkCandidates();
    $session=LoginService::getSession();$ownerId=(int)$session->getOwner();
    $db->query("SELECT c.id,c.title,c.content_type,c.status,r.route
        FROM cms_contents c
        LEFT JOIN cms_routes r ON r.id_content=c.id AND r.is_main=1
        WHERE c.id_owner=:owner AND c.status IN ('GENERATED','PUBLISHED')
        ORDER BY c.updated_at DESC LIMIT 300");
    $db->bind(':owner',$ownerId);$baseContents=$db->fetchAll();

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Create CMS Page",
        "errors" => [],
        "templates" => $templates,
        "categories" => $categories,
        "internalLinks" => $internalLinks,
        "baseContents" => $baseContents,
        "old" => [
            "id_template" => "",
            "id_cms_category" => "",
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

    $categoriesRepository = new CmsCategoriesRepository();
    $categoriesRepository->db = $db;

    $routesRepository = new CmsRoutesRepository();
    $routesRepository->db = $db;
    $sessionUser = LoginService::getSession();
    $authorUserId = $sessionUser ? (int)$sessionUser->getId() : null;
    $ownerId = $sessionUser && $sessionUser->getOwner() ? (int)$sessionUser->getOwner() : SiteContext::businessOwnerId();

    $templates = $templatesRepository->getActive();
    $categories = $categoriesRepository->getActive();

    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'ai_generate_metadata') {
        cmsExtendAiRuntime();
        try {
            $contentType = cmsNormalizeContentType((string)($_POST['content_type'] ?? 'page'));
            $title = trim((string)($_POST['title'] ?? ''));
            $excerpt = trim((string)($_POST['excerpt'] ?? ''));
            $bodyHtml = trim((string)($_POST['body_html'] ?? ''));
            $keywords = trim((string)($_POST['meta_keywords'] ?? ''));

            if ($title === '' && $bodyHtml === '') {
                throw new Exception('Add a title or body content before generating metadata.');
            }

            $metadata = cmsCallOpenAiJson(
                'You are an SEO editor for VNV Events LLC. Return valid JSON only.',
                [
                    'task' => 'Generate CMS metadata from this draft. Required keys: meta_title, meta_description, meta_keywords, og_title, og_description, schema_json, thumbnail_prompt.',
                    'brand' => 'VNV Events LLC',
                    'content_type' => $contentType,
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'keywords_csv' => $keywords,
                    'body_html_excerpt' => substr(strip_tags($bodyHtml), 0, 4500),
                    'rules' => [
                        'Visible text must be in English.',
                        'Do not invent prices, addresses, awards, reviews, guarantees, licenses or staff names.',
                        'meta_title should be concise and click-worthy.',
                        'meta_description should be useful, natural and no longer than 160 characters.',
                        'schema_json should be a valid JSON-LD object appropriate for the content type.',
                        'thumbnail_prompt must describe hyperrealistic professional event photography for VNV Events, not illustration, cartoon, anime or render, and without text overlays.',
                    ],
                ],
                90
            );

            $schemaJson = $metadata['schema_json'] ?? null;
            if (is_array($schemaJson)) {
                $schemaJson = json_encode($schemaJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $thumbnail = null;
            $thumbnailError = null;
            $thumbnailPrompt = trim((string)($metadata['thumbnail_prompt'] ?? ''));
            if ($thumbnailPrompt !== '') {
                try {
                    $imageService = new CmsImageGenerationService();
                    $images = $imageService->generateMany([$thumbnailPrompt], 1, 'cms/generated-thumbnails');
                    $thumbnail = $images[0] ?? null;
                } catch (Exception $imageError) {
                    $thumbnailError = $imageError->getMessage();
                }
            }

            cmsJsonResponse([
                'ok' => true,
                'metadata' => [
                    'meta_title' => trim((string)($metadata['meta_title'] ?? $title)),
                    'meta_description' => trim((string)($metadata['meta_description'] ?? '')),
                    'meta_keywords' => trim((string)($metadata['meta_keywords'] ?? $keywords)),
                    'og_title' => trim((string)($metadata['og_title'] ?? $title)),
                    'og_description' => trim((string)($metadata['og_description'] ?? $metadata['meta_description'] ?? '')),
                    'schema_json' => is_string($schemaJson) ? $schemaJson : '',
                ],
                'thumbnail' => $thumbnail,
                'thumbnail_error' => $thumbnailError,
            ]);
        } catch (Exception $e) {
            cmsJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    if ($action === 'ai_generate_ideas') {
        cmsExtendAiRuntime();
        try {
            $contentType = cmsNormalizeContentType((string)($_POST['content_type'] ?? 'blog'));
            $keywords = trim((string)($_POST['keywords'] ?? ''));
            $manualTitle = trim((string)($_POST['manual_title'] ?? ''));
            $intent = trim((string)($_POST['intent'] ?? 'informational'));
            $idCmsCategory = (int)($_POST['id_cms_category'] ?? 0);
            $categoryName = '';
            foreach ($categories as $category) {
                if ((int)$category->id === $idCmsCategory) {
                    $categoryName = (string)$category->name;
                    break;
                }
            }

            $reference = cmsFetchRemoteReference(($manualTitle !== '' ? $manualTitle : $keywords) . ' VNV Events Miami');
            $ideasPayload = cmsCallOpenAiJson(
                'You are an editorial strategist for VNV Events. Return compact valid JSON only.',
                [
                    'task' => 'Generate exactly 5 ideas. Each idea must include title, excerpt, angle, suggested_keywords and reference_note.',
                    'brand' => 'VNV Events LLC',
                    'content_type' => $contentType,
                    'category' => $categoryName,
                    'keywords_csv' => $keywords,
                    'manual_title' => $manualTitle,
                    'intent' => $intent,
                    'remote_reference' => $reference,
                    'rules' => [
                        'Do not invent prices, addresses, awards, reviews, guarantees, licenses or staff names.',
                        'Make every excerpt useful and customer-facing.',
                        'Visible content must be in English.',
                    ],
                ],
                90
            );

            $ideas = $ideasPayload['ideas'] ?? [];
            if (!is_array($ideas) || count($ideas) === 0) {
                throw new Exception('AI did not return ideas.');
            }

            cmsJsonResponse([
                'ok' => true,
                'ideas' => array_slice(array_values($ideas), 0, 5),
                'reference' => $reference,
            ]);
        } catch (Exception $e) {
            cmsJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    if ($action === 'ai_create_articles') {
        cmsExtendAiRuntime();
        try {
            $contentType = cmsNormalizeContentType((string)($_POST['content_type'] ?? 'blog'));
            $idCmsCategory = (int)($_POST['id_cms_category'] ?? 0);
            $idTemplate = cmsTemplateIdForType($templates, $contentType, (int)($_POST['id_template'] ?? 0));
            $keywords = trim((string)($_POST['keywords'] ?? ''));
            $intent = trim((string)($_POST['intent'] ?? 'informational'));
            $supportingImageCount = max(0, min(4, (int)($_POST['image_count'] ?? 0)));
            $batchIndex = max(0, (int)($_POST['batch_index'] ?? 0));
            $batchTotal = max(1, min(3, (int)($_POST['batch_total'] ?? 1)));
            $batchToken = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['batch_token'] ?? ''));
            $reference = json_decode((string)($_POST['reference_json'] ?? '{}'), true);
            $reference = is_array($reference) ? $reference : [];
            $baseContentId=(int)($_POST['base_content_id']??0);$baseContent=null;
            if($baseContentId>0){$db->query("SELECT id,title,content_type,excerpt,body_html,meta_title,meta_description,schema_json FROM cms_contents WHERE id=:id AND id_owner=:owner LIMIT 1");$db->bind(':id',$baseContentId);$db->bind(':owner',$ownerId);$baseContent=$db->fetchOne()?:null;}
            $internalLinks = json_decode((string)($_POST['internal_links_json'] ?? '[]'), true);
            $internalLinks = is_array($internalLinks) ? array_slice(array_values($internalLinks), 0, 8) : [];
            $ideas = json_decode((string)($_POST['selected_ideas'] ?? '[]'), true);
            if (!is_array($ideas) || count($ideas) === 0) {
                throw new Exception('Select at least one idea.');
            }
            $ideas = array_slice(array_values($ideas), 0, 3);

            $created = [];
            $failed = [];
            foreach ($ideas as $idea) {
                if (!is_array($idea)) {
                    continue;
                }

                $article = cmsCallOpenAiJson(
                    'You are a careful SEO content writer for VNV Events. Return valid JSON only.',
                    [
                        'task' => 'Generate one CMS-ready draft. Required keys: title, slug, excerpt, body_html, meta_title, meta_description, meta_keywords, schema_json, og_title, og_description, image_prompts. body_html must be clean rich HTML with h2 sections, useful paragraphs, lists and FAQ when helpful. image_prompts must be hyperrealistic professional event photography prompts, not illustration, cartoon, anime or 3D render. No markdown.',
                        'brand' => 'VNV Events LLC',
                        'content_type' => $contentType,
                        'idea' => $idea,
                        'keywords_csv' => $keywords,
                        'intent' => $intent,
                        'remote_reference' => $reference,
                        'selected_vnv_base_content' => $baseContent,
                        'internal_links_to_consider' => $internalLinks,
                        'supporting_image_count' => $supportingImageCount,
                        'thumbnail_required' => $supportingImageCount > 0,
                        'rules' => [
                            'Do not invent prices, addresses, awards, reviews, guarantees, licenses or staff names.',
                            'Use remote and selected VNV base content as structural/context references only; create a distinct page unless the instruction explicitly requests adaptation.',
                            'Visible content must be in English.',
                            'Use selected internal links only when they are contextually relevant. Insert natural anchor text in body_html. Do not force every link.',
                            'Internal link href values must use the provided route exactly.',
                            'Image prompts must specify realistic people, realistic event spaces, natural lighting, true-to-life colors and no text overlays.',
                        ],
                    ],
                    120
                );

                $title = trim((string)($article['title'] ?? $idea['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $slug = cmsContentSlugify((string)($article['slug'] ?? $title));
                $baseSlug = $slug !== '' ? $slug : cmsContentSlugify($title);
                $slug = $baseSlug;
                $suffix = 2;
                while ($contentsRepository->slugExists($slug, $ownerId, 'en')) {
                    $slug = $baseSlug . '-' . $suffix;
                    $suffix++;
                }

                $route = $routesRepository->normalizeRoute(cmsRouteForContentType($contentType, $slug));
                $routeSuffix = 2;
                while ($routesRepository->routeExists($route, $ownerId, 'en')) {
                    $route = $routesRepository->normalizeRoute(cmsRouteForContentType($contentType, $slug . '-' . $routeSuffix));
                    $routeSuffix++;
                }

                $schemaJson = $article['schema_json'] ?? null;
                if (is_array($schemaJson)) {
                    $schemaJson = json_encode($schemaJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $contentJson = [
                    'generated_by' => 'cms_easy_ai',
                    'intent' => $intent,
                    'keywords' => $keywords,
                    'remote_reference' => $reference,
                    'internal_links_considered' => $internalLinks,
                    'selected_idea' => $idea,
                    'supporting_image_count_requested' => $supportingImageCount,
                    'image_prompts' => $article['image_prompts'] ?? [],
                ];
                $generatedImages = [];
                $imagePrompts = cmsAiArticleImagePrompts($article, $idea, $title, $supportingImageCount, $batchIndex, $batchToken);
                $contentJson['image_prompts_normalized'] = $imagePrompts;
                $contentJson['generation_batch'] = [
                    'token' => $batchToken,
                    'index' => $batchIndex,
                    'total' => $batchTotal,
                ];
                if ($imagePrompts !== []) {
                    try {
                        $imageService = new CmsImageGenerationService();
                        $thumbnailPrompt = (string)array_shift($imagePrompts);
                        $thumbnailImage = $imageService->generateAndUploadWithRetry($thumbnailPrompt, 'cms/generated-thumbnails', '1024x1024', 2);
                        $generatedImages[] = $thumbnailImage;

                        foreach ($imagePrompts as $supportingPrompt) {
                            try {
                                $generatedImages[] = $imageService->generateAndUploadWithRetry((string)$supportingPrompt, 'cms/generated-images', '1024x1024', 2);
                            } catch (Exception $supportingImageError) {
                                $contentJson['supporting_image_errors'][] = $supportingImageError->getMessage();
                            }
                        }
                        $contentJson['generated_images'] = $generatedImages;
                    } catch (Exception $imageError) {
                        $contentJson['image_generation_error'] = $imageError->getMessage();
                        $failed[] = [
                            'title' => $title,
                            'error' => 'Thumbnail image could not be generated: ' . $imageError->getMessage(),
                        ];
                        continue;
                    }
                }
                $featuredImageUrl = (string)($generatedImages[0]['url'] ?? '');
                $bodyImages = array_slice($generatedImages, 1);

                $ok = $contentsRepository->add($contentsRepository->withVnvEventsOrigin([
                    'id_owner' => $ownerId,
                    'id_template' => $idTemplate,
                    'id_cms_category' => $idCmsCategory > 0 ? $idCmsCategory : null,
                    'content_type' => $contentType,
                    'type' => cmsLegacyTypeForContentType($contentType),
                    'title' => $title,
                    'slug' => $slug,
                    'language' => 'en',
                    'content_mode' => 'hybrid',
                    'excerpt' => trim((string)($article['excerpt'] ?? $idea['excerpt'] ?? '')),
                    'content_json' => json_encode($contentJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'body_html' => cmsBuildAiArticleHtml($article, $supportingImageCount, $bodyImages),
                    'meta_title' => trim((string)($article['meta_title'] ?? $title)),
                    'meta_description' => trim((string)($article['meta_description'] ?? '')),
                    'meta_keywords' => trim((string)($article['meta_keywords'] ?? $keywords)),
                    'canonical_url' => cmsCanonicalUrlForRoute($route),
                    'robots' => 'index,follow',
                    'schema_json' => is_string($schemaJson) && $schemaJson !== '' ? $schemaJson : null,
                    'featured_image_url' => $featuredImageUrl !== '' ? $featuredImageUrl : null,
                    'status' => 'GENERATED',
                    'is_homepage' => 0,
                    'published_at' => null,
                    'last_generated_at' => date('Y-m-d H:i:s'),
                ], $authorUserId, $ownerId));

                if (!$ok) {
                    continue;
                }

                $contentId = $contentsRepository->getLastId();
                $routesRepository->add($routesRepository->withVnvEventsOrigin([
                    'id_owner' => $ownerId,
                    'id_content' => $contentId,
                    'route' => $route,
                    'route_hash' => md5($route),
                    'is_main' => 1,
                    'language' => 'en',
                    'public_php_path' => null,
                    'public_twig_path' => null,
                    'status' => 'ACTIVE',
                    'redirect_to' => null,
                ], $authorUserId, $ownerId));

                try {
                    $agentRepo=new AiAgentsRepository();
                    if($agentRepo->storageReady()){
                        $agentRepo->seed($ownerId,AiAgentRegistry::definitions());
                        $blogAgent=$agentRepo->find($ownerId,'blog_writer');
                        if($blogAgent){
                            $runId=$agentRepo->createRun((int)$blogAgent->id,$ownerId,'SYSTEM',$authorUserId,['content_id'=>$contentId]);
                            $approvalId=$agentRepo->createApproval($runId,(int)$blogAgent->id,$ownerId,'PUBLISH_ARTICLE','Review article: '.$title,[
                                'content_id'=>$contentId,'title'=>$title,'excerpt'=>trim((string)($article['excerpt']??$idea['excerpt']??'')),
                                'body'=>cmsBuildAiArticleHtml($article,$supportingImageCount,$bodyImages),'meta_title'=>trim((string)($article['meta_title']??$title)),
                                'meta_description'=>trim((string)($article['meta_description']??'')),'edit_url'=>LocationUtils::pathFor('panel/cms/pages/edit?id='.$contentId),
                            ],$authorUserId);
                            $agentRepo->finishRun($runId,'AWAITING_APPROVAL',['content_id'=>$contentId,'approval_id'=>$approvalId]);
                        }
                    }
                } catch (\Throwable $approvalError) {
                    error_log('[CMS AI] Approval creation failed: '.$approvalError->getMessage());
                }

                $created[] = [
                    'id' => $contentId,
                    'title' => $title,
                    'route' => $route,
                    'edit_url' => LocationUtils::pathFor('panel/cms/pages/edit?id=' . $contentId),
                    'preview_url' => LocationUtils::pathFor('panel/cms/pages/preview?id=' . $contentId),
                ];
            }

            cmsJsonResponse(['ok' => true, 'created' => $created, 'failed' => $failed]);
        } catch (Exception $e) {
            cmsJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    $idTemplate        = (int)($_POST['id_template'] ?? 0);
    $idCmsCategory     = (int)($_POST['id_cms_category'] ?? 0);
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

    if (cmsShouldUseGeneratedCanonical($canonicalUrl, $contentType, $slug)) {
        $canonicalUrl = cmsCanonicalUrlForRoute($route);
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
            "categories" => $categories,
            "old" => [
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
            ],
        ]);
    }

    $publishedAt = $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null;

    $ok = $contentsRepository->add($contentsRepository->withVnvEventsOrigin([
        "id_owner" => $ownerId,
        "id_template" => $idTemplate > 0 ? $idTemplate : null,
        "id_cms_category" => $idCmsCategory > 0 ? $idCmsCategory : null,
        "content_type" => $contentType,
        "type" => cmsLegacyTypeForContentType($contentType),
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
            "categories" => $categories,
            "old" => [
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
