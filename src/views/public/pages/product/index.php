<?php

use App\Repositories\StoreProductsRepository;
use App\Repositories\StoreProductsAudiencesRepository;
use App\Repositories\StoreProductsMealStylesRepository;
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
$audiencesRepository = new StoreProductsAudiencesRepository();
$mealStylesRepository = new StoreProductsMealStylesRepository();

$productBase = $productsRepository->getPublicBySlug($slug);

if (!$productBase) {
    http_response_code(404);
    echo "Product not found";
    exit;
}

$product = $productsRepository->getFullProductDetails((int)$productBase->id);
if (!$product) {
    http_response_code(404);
    echo "Product not found";
    exit;
}

$product->audiences = $audiencesRepository->getAudienceTypesByProduct((int)$product->id);
$product->meal_styles = $mealStylesRepository->getMealStylesByProduct((int)$product->id);

$relatedProducts = $productsRepository->getPublicRelatedProducts((int)$product->id, 8);

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    'product' => $product,
    'related_products' => $relatedProducts
]);
