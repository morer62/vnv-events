<?php

use App\Repositories\StoreProductsRepository;
use App\Repositories\Connection;
use App\Services\PublicSeoService;
use App\Services\GourmetExpressService;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];
$slug = $parts[1] ?? null;

$legacyProductRedirects = [
    'latin-holiday-dinner' => 'nochebuena-dinner',
    'thanksgiving-feast' => null,
    'extra-dessert-add-on' => null,
    'extra-hallaca' => 'hallacas-by-the-dozen',
];
if ($slug !== null && array_key_exists($slug, $legacyProductRedirects)) {
    $target = $legacyProductRedirects[$slug];
    header('Location: ' . ($target ? '/product/' . $target : '/catering-delivery'), true, 301);
    exit;
}

function product_not_found_debug(string $reason, ?string $slug = null, ?int $ownerId = null, ?string $siteKey = null, array $debug = []): never
{
    http_response_code(404);

    if (($_ENV['APP_ENV'] ?? '') === 'debug') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Product not found\n";
        echo "Reason: {$reason}\n";
        echo "URL: " . ($_GET['url'] ?? '') . "\n";
        echo "Slug: " . ($slug ?? '') . "\n";
        echo "Owner ID: " . ($ownerId !== null ? (string)$ownerId : '') . "\n";
        echo "Site Key: " . ($siteKey ?? '') . "\n";
        if ($debug !== []) {
            echo "\nDebug:\n";
            echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "\n";
        }
        exit;
    }

    echo "Product not found";
    exit;
}

if (!$slug) {
    product_not_found_debug('missing_slug', $slug);
}

function product_text_from_node(DOMNode $node): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

function product_extract_faqs_from_html(?string $html): array
{
    $html = trim((string)$html);
    if ($html === '') {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $sectionNodes = [];
    $sections = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cms-block-faq ') or contains(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'faq')]");
    foreach ($sections ?: [] as $section) {
        $sectionNodes[] = $section;
    }

    $faqHeadings = $xpath->query("//*[self::h2 or self::h3][contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'frequently asked') or contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'faq')]");
    foreach ($faqHeadings ?: [] as $heading) {
        $parent = $heading->parentNode;
        if ($parent instanceof DOMElement) {
            $sectionNodes[] = $parent;
        }
    }

    $faqs = [];
    foreach ($sectionNodes as $section) {
        $detailsNodes = $xpath->query(".//details", $section);
        foreach ($detailsNodes ?: [] as $details) {
            $summary = $xpath->query("./summary", $details)->item(0);
            $question = $summary ? product_text_from_node($summary) : '';
            $answerParts = [];

            foreach ($details->childNodes as $child) {
                if ($child->isSameNode($summary)) {
                    continue;
                }

                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'div', 'ul', 'ol'], true)) {
                    $text = product_text_from_node($child);
                    if ($text !== '') {
                        $answerParts[] = $text;
                    }
                }
            }

            $answer = trim(implode(' ', $answerParts));
            if ($question !== '' && $answer !== '') {
                $faqs[] = [
                    'question' => $question,
                    'answer' => $answer,
                ];
            }
        }

        $headings = $xpath->query(".//h3|.//h4|.//h5", $section);
        foreach ($headings ?: [] as $heading) {
            $question = product_text_from_node($heading);
            if ($question === '') {
                continue;
            }

            $answerParts = [];
            for ($node = $heading->nextSibling; $node !== null; $node = $node->nextSibling) {
                if ($node instanceof DOMElement && preg_match('/^h[1-6]$/i', $node->tagName)) {
                    break;
                }

                if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['p', 'ul', 'ol'], true)) {
                    $text = product_text_from_node($node);
                    if ($text !== '') {
                        $answerParts[] = $text;
                    }
                }
            }

            $answer = trim(implode(' ', $answerParts));
            if ($answer !== '') {
                $faqs[] = [
                    'question' => $question,
                    'answer' => $answer,
                ];
            }
        }
    }

    $deduped = [];
    foreach ($faqs as $faq) {
        $key = strtolower($faq['question']);
        if (!isset($deduped[$key])) {
            $deduped[$key] = $faq;
        }
    }

    return array_values($deduped);
}

$productsRepository = new StoreProductsRepository();
$ownerId = AvomealContext::ownerId();
$siteKey = SiteContext::siteKey();

$productBase = $productsRepository->getPublicBySlug($slug, $ownerId, $siteKey);

if (!$productBase) {
    $rawProduct = $productsRepository->getBySlug($slug, $ownerId, $siteKey);
    $reason = $rawProduct
        ? 'public_visibility_or_public_status_failed_for_product_id_' . (int)$rawProduct->id
        : 'slug_owner_site_lookup_failed';

    $debug = method_exists($productsRepository, 'debugPublicProductLookup')
        ? $productsRepository->debugPublicProductLookup($slug, $ownerId, $siteKey)
        : [];

    product_not_found_debug($reason, $slug, $ownerId, $siteKey, $debug);
}

$product = $productsRepository->getFullPublicProductDetails((int)$productBase->id, $ownerId, $siteKey);

if (!$product) {
    product_not_found_debug('full_public_product_details_failed_for_product_id_' . (int)$productBase->id, $slug, $ownerId, $siteKey);
}

// Add-ons can be selected inside an eligible product flow, but do not have a
// standalone commercial landing page or indexable URL.
if ((int)($product->is_addon_only ?? 0) === 1 || strtoupper((string)($product->product_role ?? 'MAIN')) === 'ADDON') {
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Location: ' . \App\Utils\LocationUtils::pathFor('catering-delivery'), true, 302);
    exit;
}

$relatedProducts = method_exists($productsRepository, 'getPublicRelatedProducts')
    ? $productsRepository->getPublicRelatedProducts((int)$product->id, 8)
    : [];

$storeActiveRaw = $_ENV['STORE_ACTIVE'] ?? getenv('STORE_ACTIVE') ?? 'YES';
$storeActive = strtoupper(trim((string)$storeActiveRaw)) === 'YES'
    || (
        strtoupper((string)($product->purchase_mode ?? 'REQUEST')) === 'DIRECT'
        && (int)($product->allow_immediate_payment ?? 0) === 1
    );
$productFaqs = product_extract_faqs_from_html($product->description ?? '');
$foodProfile = null;
$productRecommendations = [];
$productAddons = [];
try {
    $db = new Connection();
    $db->query('SELECT * FROM store_product_food_profiles WHERE id_owner=:owner AND site_key=:site AND id_product=:product LIMIT 1');
    $db->bind(':owner', $ownerId, \PDO::PARAM_INT);
    $db->bind(':site', $siteKey);
    $db->bind(':product', (int)$product->id, \PDO::PARAM_INT);
    $foodProfile = $db->fetchOne() ?: null;
    if ($foodProfile) {
        $foodProfile->cooking_preferences = json_decode((string)($foodProfile->cooking_preferences_json ?? '[]'), true) ?: [];
    }
    $db->query("SELECT * FROM store_product_recommendations WHERE id_owner=:owner AND site_key=:site AND id_product=:product AND status='ACTIVE' ORDER BY sort_order,id");
    $db->bind(':owner', $ownerId, \PDO::PARAM_INT);
    $db->bind(':site', $siteKey);
    $db->bind(':product', (int)$product->id, \PDO::PARAM_INT);
    $productRecommendations = $db->fetchAll() ?: [];
    $db->query("SELECT p.*,r.price_override,r.sort_order FROM store_product_relationships r INNER JOIN store_products p ON p.id=r.id_related_product WHERE r.id_owner=:owner AND r.site_key=:site AND r.id_product=:product AND r.relationship_type IN ('ADD_ON','OPTIONAL_SIDE','PAID_SIDE') AND r.status='ACTIVE' AND p.status='ACTIVE' AND p.is_public=1 ORDER BY r.sort_order,r.id");
    $db->bind(':owner', $ownerId, \PDO::PARAM_INT);
    $db->bind(':site', $siteKey);
    $db->bind(':product', (int)$product->id, \PDO::PARAM_INT);
    $productAddons = $db->fetchAll() ?: [];
    $variationsRepo = new \App\Repositories\StoreProductVariationsRepository();
    foreach ($productAddons as $addon) {
        $addon->variations = strtoupper((string)$addon->product_type) === 'VARIABLE' ? $variationsRepo->getActiveByProduct((int)$addon->id) : [];
        foreach ($addon->variations as $variation) $variation->effective_price = $variationsRepo->getEffectivePrice($variation);
    }
} catch (Throwable $e) {
    error_log('[Product food profile] ' . $e->getMessage());
}

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    'product' => $product,
    'related_products' => $relatedProducts,
    'food_profile' => $foodProfile,
    'product_recommendations' => $productRecommendations,
    'product_addons' => $productAddons,
    'store_active' => $storeActive,
    'gourmet_settings' => (new GourmetExpressService())->settings($ownerId, $siteKey),
    'is_gourmet_express' => (string)($product->brand_name ?? '') === 'VNV Gourmet Express',
    'schemaJson' => PublicSeoService::productSchema($product, $productFaqs),
]);
