<?php

use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];
$slug = $parts[1] ?? null;

function product_not_found_debug(string $reason, ?string $slug = null, ?int $ownerId = null, ?string $siteKey = null): never
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
        exit;
    }

    echo "Product not found";
    exit;
}

if (!$slug) {
    product_not_found_debug('missing_slug', $slug);
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

    product_not_found_debug($reason, $slug, $ownerId, $siteKey);
}

$product = $productsRepository->getFullPublicProductDetails((int)$productBase->id, $ownerId, $siteKey);

if (!$product) {
    product_not_found_debug('full_public_product_details_failed_for_product_id_' . (int)$productBase->id, $slug, $ownerId, $siteKey);
}

$relatedProducts = method_exists($productsRepository, 'getPublicRelatedProducts')
    ? $productsRepository->getPublicRelatedProducts((int)$product->id, 8)
    : [];

$storeActiveRaw = $_ENV['STORE_ACTIVE'] ?? getenv('STORE_ACTIVE') ?? 'YES';
$storeActive = strtoupper(trim((string)$storeActiveRaw)) === 'YES';

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    'product' => $product,
    'related_products' => $relatedProducts,
    'store_active' => $storeActive
]);
