<?php

use App\Repositories\LocationPagesRepository;
use App\Services\LoginService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

function getDefaultTemplateCss(string $templateKey): string
{
    if ($templateKey === 'location-home-luxe') {
        return <<<CSS
@import url('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Playfair+Display:wght@600;700;800&display=swap');
:root{
  --vnv-primary:#5EC6C4;
  --vnv-primary-dark:#49acab;
  --vnv-luxe-gold:#c5a059;
  --vnv-luxe-gold-soft:#f0d4a0;
  --vnv-gold-gradient:linear-gradient(135deg,#bf953f 0%,#fcf6ba 45%,#b38728 100%);
  --vnv-luxe-dark:#0b0f11;
  --vnv-luxe-dark-soft:#151c20;
  --vnv-luxe-light:#f8fafb;
  --vnv-luxe-text:#d3dbe1;
}
body{
  font-family:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;
  background:var(--vnv-luxe-dark);
  color:var(--vnv-luxe-light);
}
h1,h2,h3,h4,.lp-title,.luxe-title{
  font-family:'Playfair Display','Times New Roman',serif;
  letter-spacing:.01em;
}
p,li,span{color:var(--vnv-luxe-text);}
.lp-hero::after{background:radial-gradient(circle at top right, rgba(94,198,196,.22), transparent 36%);}
.lp-badge{background:rgba(94,198,196,.14)!important;color:var(--vnv-primary)!important;border:1px solid rgba(94,198,196,.36);}
.lp-title{text-shadow:0 6px 26px rgba(0,0,0,.38);}
.lp-chip{border:1px solid rgba(197,160,89,.48)!important;color:#fff!important;background:rgba(197,160,89,.12);}
.lp-card{
  border:1px solid rgba(197,160,89,.26)!important;
  border-radius:20px;
  background:linear-gradient(160deg,#ffffff,#fbfbfd);
  box-shadow:0 18px 38px rgba(8,11,13,.12);
}
.lp-card:hover{
  transform:translateY(-4px);
  box-shadow:0 24px 48px rgba(8,11,13,.18)!important;
  border-color:rgba(197,160,89,.45)!important;
}
.lp-block-title{color:#10181d!important;}
.lp-content h2,.lp-content h3{color:#10181d!important;}
.lp-content a{color:#0f8f8b;font-weight:700;}
.lp-link-item{border-color:rgba(94,198,196,.24)!important;}
.lp-link-item:hover{border-color:rgba(197,160,89,.46)!important;background:#fffaf1;}
.lp-info{border-color:rgba(94,198,196,.28)!important;background:linear-gradient(165deg,#fff,#f8fcfc);}
.lp-info .k{color:#0f8f8b!important;}
.lp-info .v{color:#0f172a!important;}
.lp-img{cursor:zoom-in;}
.lp-img:hover{box-shadow:0 20px 38px rgba(0,0,0,.22)!important;}
.lp-testimonial-item{
  border:1px solid rgba(197,160,89,.34);
  border-radius:18px;
  padding:18px;
  background:linear-gradient(145deg,rgba(197,160,89,.14),rgba(94,198,196,.08));
}
.lp-testimonial-item:hover{box-shadow:0 18px 30px rgba(12,18,22,.16);}
.luxe-title,.lp-block-title{color:var(--vnv-luxe-gold);letter-spacing:.02em;}
.lp-faq .accordion-button{font-weight:700;color:#10181d;}
.lp-faq .accordion-button:not(.collapsed){background:rgba(197,160,89,.12);color:#10181d;}
.lp-lightbox-overlay{background:rgba(8,11,13,.9)!important;}
CSS;
    }

    return '';
}

function getDefaultDynamicBlocksJson(string $templateKey): ?string
{
    if ($templateKey !== 'location-home-luxe') {
        return null;
    }

    $blocks = [
        [
            'type' => 'info',
            'title' => 'Quick Information',
            'items' => [
                ['label' => 'Phone', 'value' => '+1 305-204-5427'],
                ['label' => 'Email', 'value' => 'info@vnvevents.com'],
                ['label' => 'Hours', 'value' => 'Mon - Fri 10 AM - 5 PM'],
                ['label' => 'Saturday', 'value' => '10 AM - 2 PM'],
                ['label' => 'Sunday', 'value' => 'Closed'],
                ['label' => 'Address', 'value' => '10258 NW 47th St, Sunrise, FL 33351']
            ]
        ],
        [
            'type' => 'images',
            'title' => 'Gallery',
            'columns' => 3,
            'images' => []
        ],
        [
            'type' => 'testimonials',
            'title' => 'What Clients Say',
            'enabled' => true,
            'items' => [
                [
                    'quote' => 'VNV Events made our wedding weekend look and feel like a luxury production.',
                    'name' => 'Sofia M.',
                    'role' => 'Bride'
                ],
                [
                    'quote' => 'From timeline to guest experience, every detail felt intentional and elegant.',
                    'name' => 'Carla R.',
                    'role' => 'Corporate Client'
                ],
                [
                    'quote' => 'Professional team, impeccable execution, and a truly glamorous setup.',
                    'name' => 'Alyssa D.',
                    'role' => 'Private Event Host'
                ]
            ]
        ],
        [
            'type' => 'map',
            'title' => 'Address',
            'address' => '10258 NW 47th St, Sunrise, FL 33351'
        ]
    ];

    return json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function normalizeDynamicBlocksJson(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    foreach ($decoded as &$block) {
        if (!is_array($block)) {
            $block = ['type' => 'text', 'title' => '', 'content' => ''];
            continue;
        }

        $type = trim((string)($block['type'] ?? 'text'));
        $block['type'] = $type !== '' ? $type : 'text';
        $block['title'] = (string)($block['title'] ?? '');
        $block['content'] = (string)($block['content'] ?? '');
        $block['address'] = (string)($block['address'] ?? '');
        $block['image_url'] = (string)($block['image_url'] ?? '');
        $block['columns'] = max(1, min(4, (int)($block['columns'] ?? 3)));
        $block['lat'] = isset($block['lat']) && $block['lat'] !== '' ? (float)$block['lat'] : null;
        $block['lng'] = isset($block['lng']) && $block['lng'] !== '' ? (float)$block['lng'] : null;
        $block['enabled'] = !isset($block['enabled']) || (bool)$block['enabled'];

        $items = $block['items'] ?? [];
        if (!is_array($items)) $items = [];
        $links = $block['links'] ?? [];
        if (!is_array($links)) $links = [];
        $images = $block['images'] ?? [];
        if (!is_array($images)) $images = [];

        if ($block['type'] === 'images' && empty($images) && $block['image_url'] !== '') {
            $images = [[
                'url' => $block['image_url'],
                'alt' => $block['title'] !== '' ? $block['title'] : 'Location image'
            ]];
        }

        $block['items'] = array_values($items);
        $block['links'] = array_values($links);
        $block['images'] = array_values($images);
    }
    unset($block);

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$router->get(function () {
    $repo = new LocationPagesRepository();
    $id = (int)($_GET['id'] ?? 0);

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

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Edit Location Page",
        "page" => $page
    ]);
});

$router->post(function () {
    $repo = new LocationPagesRepository();
    $user = LoginService::getSession();

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
    $schemaJson = trim($_POST['schema_json'] ?? '');
    $metaTitle = trim($_POST['meta_title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $metaKeywords = trim($_POST['meta_keywords'] ?? '');
    $ogTitle = trim($_POST['og_title'] ?? '');
    $ogDescription = trim($_POST['og_description'] ?? '');
    $ogImage = trim($_POST['og_image'] ?? '');
    $canonicalUrl = trim($_POST['canonical_url'] ?? '');
    $customCss = trim($_POST['custom_css'] ?? '');
    $customJs = trim($_POST['custom_js'] ?? '');
    $status = strtoupper(trim($_POST['status'] ?? 'DRAFT'));
    $isIndexable = isset($_POST['is_indexable']) ? 1 : 0;

    if ($title === '' || $slug === '') {
        MessageUtil::setMessage("Title and slug are required.");
        LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
    }

    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        MessageUtil::setMessage("Invalid slug.");
        LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
    }

    if ($repo->slugExists($slug, $id)) {
        MessageUtil::setMessage("That slug already exists.");
        LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
    }

    if ($faqJson !== '' && json_decode($faqJson, true) === null) {
        MessageUtil::setMessage("FAQ JSON is invalid.");
        LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
    }

    $dynamicBlocksJson = normalizeDynamicBlocksJson($dynamicBlocksJson);
    if (trim($_POST['dynamic_blocks_json'] ?? '') !== '' && $dynamicBlocksJson === null) {
        MessageUtil::setMessage("Dynamic Blocks JSON is invalid.");
        LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
    }

    if ($customCss === '') {
        $customCss = getDefaultTemplateCss($templateKey);
    }
    if ($dynamicBlocksJson === null) {
        $dynamicBlocksJson = getDefaultDynamicBlocksJson($templateKey);
    }

    if ($schemaJson !== '' && json_decode($schemaJson, true) === null) {
        MessageUtil::setMessage("Schema JSON is invalid.");
        LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
    }

    if (!in_array($status, ['DRAFT', 'PUBLISHED'], true)) {
        $status = 'DRAFT';
    }
    if ($canonicalUrl === '') {
        $canonicalUrl = SiteContext::publicBaseUrl() . '/locations/' . $slug . '/';
    }

    $currentPublishedAt = $page->published_at ?? null;
    $newPublishedAt = $currentPublishedAt;

    if ($status === 'PUBLISHED' && empty($currentPublishedAt)) {
        $newPublishedAt = date('Y-m-d H:i:s');
    }

    if ($status === 'DRAFT') {
        $newPublishedAt = null;
    }

    $authorUserId = $user ? (int)$user->getId() : null;
    $ownerId = $user && $user->getOwner() ? (int)$user->getOwner() : SiteContext::businessOwnerId();

    $repo->update($repo->withVnvEventsOrigin([
        'id_owner' => $ownerId,
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
        'published_at' => $newPublishedAt,
    ], $authorUserId, $ownerId), [
        'id' => $id
    ]);

    MessageUtil::setMessage("Location page updated successfully.");
    LocationUtils::redirectInternal("panel/cms/location-pages/edit?id=" . $id);
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
