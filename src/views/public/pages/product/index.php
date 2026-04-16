<?php

use App\Repositories\StoreProductsRepository;
use App\Utils\TemplateResponse;

$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];
$slug = $parts[1] ?? null;

if (!$slug) {
    http_response_code(404);
    echo "Product not found";
    exit;
}

$productsRepository = new StoreProductsRepository();

$productBase = $productsRepository->getPublicBySlug($slug);

if (!$productBase) {
    http_response_code(404);
    echo "Product not found";
    exit;
}

$product = $productsRepository->getFullPublicProductDetails((int)$productBase->id);

if (!$product) {
    http_response_code(404);
    echo "Product not found";
    exit;
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