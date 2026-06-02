<?php

use App\Repositories\StoreCategoriesRepository;
use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\TemplateResponse;

$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];
$slug = $parts[1] ?? null;

if (!$slug) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

$categoriesRepository = new StoreCategoriesRepository();
$productsRepository = new StoreProductsRepository();
$ownerId = AvomealContext::ownerId();

$category = $categoriesRepository->getPublicBySlug($slug, $ownerId);

if (!$category || ($category->status ?? null) !== StoreCategoriesRepository::STATUS_ACTIVE) {
    http_response_code(404);
    echo "Category not found";
    exit;
}

$products = $productsRepository->getPublicByCategory((int)$category->id, 120, $ownerId);

$blocks = [];
if (!empty($category->page_builder_json)) {
    $decoded = json_decode((string)$category->page_builder_json, true);
    if (is_array($decoded)) {
        $blocks = $decoded;
    }
}

if (empty($blocks)) {
    $blocks = [
        [
            'type' => 'hero',
            'title' => $category->name ?? 'Category',
            'subtitle' => $category->description ?? ''
        ],
        [
            'type' => 'product_grid',
            'title' => 'Products',
            'limit' => 24,
            'columns' => 3
        ]
    ];
}

echo TemplateResponse::render(__DIR__ . "/index.twig", [
    'category' => $category,
    'products' => $products,
    'blocks' => $blocks
]);
