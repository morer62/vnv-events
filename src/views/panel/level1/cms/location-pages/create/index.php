<?php

use App\Repositories\LocationPagesRepository;
use App\Services\LoginService;
use App\Services\LocationPageAIService;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$router = new Router();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'ai_generate_location_page')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $dynamicBlocksRaw = trim((string)($_POST['dynamic_blocks_json'] ?? ''));
        $dynamicBlocks = [];
        if ($dynamicBlocksRaw !== '') {
            $decoded = json_decode($dynamicBlocksRaw, true);
            if (is_array($decoded)) {
                $dynamicBlocks = $decoded;
            }
        }

        $result = LocationPageAIService::generate([
            'location' => trim((string)($_POST['location'] ?? '')),
            'slug' => trim((string)($_POST['slug'] ?? '')),
            'template_key' => trim((string)($_POST['template_key'] ?? 'location-home-luxe')),
            'dynamic_blocks' => $dynamicBlocks,
        ]);

        echo json_encode([
            'ok' => true,
            'data' => $result
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function getDefaultTemplateCss(string $templateKey): string
{
    if ($templateKey === 'location-home-luxe') {
        return <<<CSS
@import url('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Playfair+Display:wght@600;700;800&display=swap');
:root{
  --vnv-primary:#78d8d3;
  --vnv-primary-dark:#4aaea9;
  --vnv-luxe-gold:#d3ad62;
  --vnv-luxe-gold-soft:#f5dcaa;
  --vnv-gold-gradient:linear-gradient(140deg,#bf953f 0%,#f9e7b4 40%,#ad7f2a 100%);
  --vnv-luxe-dark:#05080b;
  --vnv-luxe-dark-soft:#0d151c;
  --vnv-luxe-light:#f8fbfd;
  --vnv-luxe-text:#d6e0e8;
}
body{
  font-family:'Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;
  background:radial-gradient(1100px 520px at 82% -10%,rgba(211,173,98,.12),transparent 48%),var(--vnv-luxe-dark);
  color:var(--vnv-luxe-light);
}
h1,h2,h3,h4,.lp-title,.luxe-title{
  font-family:'Playfair Display','Times New Roman',serif;
  letter-spacing:.01em;
}
p,li,span{color:var(--vnv-luxe-text);}
.lp-hero{
  background-position:center center!important;
  background-size:cover!important;
}
.lp-hero::before{
  content:"";
  position:absolute;
  inset:0;
  pointer-events:none;
  background:linear-gradient(90deg,rgba(5,8,11,.9) 0%,rgba(5,8,11,.68) 46%,rgba(5,8,11,.35) 100%);
}
.lp-hero::after{background:radial-gradient(circle at 84% 8%, rgba(211,173,98,.24), transparent 42%);}
.lp-hero-inner{position:relative;z-index:2;}
.lp-badge{
  background:rgba(211,173,98,.12)!important;
  color:var(--vnv-luxe-gold-soft)!important;
  border:1px solid rgba(211,173,98,.45);
  backdrop-filter:blur(2px);
}
.lp-title{
  color:#fff;
  text-shadow:0 10px 35px rgba(0,0,0,.55);
  max-width:840px;
}
.lp-sub{max-width:700px;color:#d9e1e7!important;}
.lp-chip{
  border:1px solid rgba(211,173,98,.48)!important;
  color:#fff!important;
  background:rgba(211,173,98,.08);
}
.lp-btn-gold{
  background:var(--vnv-gold-gradient)!important;
  box-shadow:0 12px 26px rgba(191,149,63,.34);
}
.lp-btn-ghost{
  border:1px solid rgba(230,236,241,.35)!important;
  background:rgba(7,11,15,.24)!important;
}
.lp-card{
  border:1px solid rgba(211,173,98,.24)!important;
  border-radius:22px;
  background:linear-gradient(165deg,#071018,#091420);
  box-shadow:0 18px 44px rgba(1,4,7,.42);
}
.lp-card:hover{
  transform:translateY(-4px);
  box-shadow:0 24px 52px rgba(0,0,0,.48)!important;
  border-color:rgba(211,173,98,.5)!important;
}
.lp-block-title{color:var(--vnv-luxe-gold)!important;text-transform:uppercase;letter-spacing:.08em;font-size:.9rem;}
.lp-content,.lp-content p,.lp-content li{color:#d7e1e8!important;}
.lp-content h2,.lp-content h3{color:#f8fbfd!important;}
.lp-content a{color:#9de5de;font-weight:700;}
.lp-link-item{
  border:1px solid rgba(120,216,211,.28)!important;
  background:rgba(7,14,20,.68)!important;
  color:#eef4f7!important;
}
.lp-link-item:hover{border-color:rgba(211,173,98,.5)!important;background:rgba(16,24,32,.95)!important;}
.lp-info{
  border:1px solid rgba(211,173,98,.24)!important;
  background:linear-gradient(160deg,rgba(6,12,18,.88),rgba(8,15,22,.95));
}
.lp-info .k{color:#f0dcb2!important;}
.lp-info .v{color:#f4f8fb!important;}
.lp-img{cursor:zoom-in;}
.lp-img:hover{box-shadow:0 20px 38px rgba(0,0,0,.22)!important;}
.lp-testimonial-item{
  border:1px solid rgba(211,173,98,.32);
  border-radius:18px;
  padding:18px;
  background:linear-gradient(145deg,rgba(211,173,98,.1),rgba(120,216,211,.07));
}
.lp-testimonial-item:hover{box-shadow:0 18px 34px rgba(0,0,0,.36);}
.lp-testimonial-quote{color:#e3ebf2!important;}
.lp-testimonial-name{color:#fff!important;}
.lp-testimonial-role{color:#e4c98f!important;}
.lp-map-canvas{border:1px solid rgba(211,173,98,.34)!important;filter:saturate(1.08) contrast(1.05);}
.luxe-title,.lp-block-title{color:var(--vnv-luxe-gold);letter-spacing:.02em;}
.lp-faq .accordion-item{background:rgba(7,12,18,.75)!important;border:1px solid rgba(211,173,98,.22)!important;}
.lp-faq .accordion-button{font-weight:700;color:#edf4f7;background:rgba(9,14,20,.86);}
.lp-faq .accordion-button:not(.collapsed){background:rgba(211,173,98,.14);color:#f8e2b8;}
.lp-faq .accordion-body{color:#d5dfe7;background:rgba(7,12,18,.88);}
.lp-lightbox-overlay{background:rgba(8,11,13,.9)!important;}
@media (max-width: 991px){
  .lp-title{font-size:clamp(1.9rem,6vw,2.8rem);}
  .lp-hero{padding-top:4.75rem;padding-bottom:4.25rem;}
}
@media (max-width: 640px){
  .lp-card{border-radius:16px;}
  .lp-testimonial-item{padding:14px;border-radius:14px;}
  .lp-chip{font-size:.78rem;}
}
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
            'images' => [
                ['url' => 'https://images.unsplash.com/photo-1587776215053-d991fe770b4e?q=80&w=764&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D', 'alt' => 'Luxury event decor'],
                ['url' => 'https://plus.unsplash.com/premium_photo-1711305772609-a59b3f90f629?q=80&w=687&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D', 'alt' => 'Elegant wedding setup'],
                ['url' => 'https://images.unsplash.com/photo-1651065698373-f310e3b99326?q=80&w=1470&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D', 'alt' => 'Premium event atmosphere']
            ]
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

        // If image block only has image_url, convert to images list for consistency.
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
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        "title" => "Create Location Page",
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

    $status = strtoupper(trim($_POST['status'] ?? 'DRAFT'));
    $isIndexable = isset($_POST['is_indexable']) ? 1 : 0;

    if ($title === '' || $slug === '') {
        MessageUtil::setMessage("Title and slug are required.");
        LocationUtils::redirectInternal("panel/cms/location-pages/create");
    }

    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        MessageUtil::setMessage("Invalid slug.");
        LocationUtils::redirectInternal("panel/cms/location-pages/create");
    }

    if ($repo->slugExists($slug)) {
        MessageUtil::setMessage("That slug already exists.");
        LocationUtils::redirectInternal("panel/cms/location-pages/create");
    }

    if ($faqJson !== '' && json_decode($faqJson, true) === null) {
        MessageUtil::setMessage("FAQ JSON is invalid.");
        LocationUtils::redirectInternal("panel/cms/location-pages/create");
    }

    $dynamicBlocksJson = normalizeDynamicBlocksJson($dynamicBlocksJson);
    if (trim($_POST['dynamic_blocks_json'] ?? '') !== '' && $dynamicBlocksJson === null) {
        MessageUtil::setMessage("Dynamic Blocks JSON is invalid.");
        LocationUtils::redirectInternal("panel/cms/location-pages/create");
    }

    if ($schemaJson !== '' && json_decode($schemaJson, true) === null) {
        MessageUtil::setMessage("Schema JSON is invalid.");
        LocationUtils::redirectInternal("panel/cms/location-pages/create");
    }

    if (!in_array($status, ['DRAFT', 'PUBLISHED'], true)) {
        $status = 'DRAFT';
    }

    if ($customCss === '') {
        $customCss = getDefaultTemplateCss($templateKey);
    }
    if ($dynamicBlocksJson === null) {
        $dynamicBlocksJson = getDefaultDynamicBlocksJson($templateKey);
    }
    if ($canonicalUrl === '') {
        $canonicalUrl = SiteContext::publicBaseUrl() . '/locations/' . $slug . '/';
    }

    $authorUserId = $user ? (int)$user->getId() : null;
    $ownerId = $user && $user->getOwner() ? (int)$user->getOwner() : SiteContext::businessOwnerId();

    $repo->add($repo->withVnvEventsOrigin([
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
        'published_at' => $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null,
    ], $authorUserId, $ownerId));

    MessageUtil::setMessage("Location page created successfully.");
    LocationUtils::redirectInternal("panel/cms/location-pages");
});

try {
    $router->run();
} catch (Exception $e) {
    echo $e->getMessage();
}
