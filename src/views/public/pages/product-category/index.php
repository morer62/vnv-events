<?php

use App\Repositories\StoreCategoriesRepository;
use App\Repositories\StoreProductsRepository;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$url = trim($_GET['url'] ?? '', '/');
$parts = $url !== '' ? explode('/', $url) : [];
$slug = $parts[1] ?? null;

function category_not_found_debug(string $reason, ?string $slug = null, ?int $ownerId = null, ?string $siteKey = null, array $debug = []): never
{
    http_response_code(404);

    if (($_ENV['APP_ENV'] ?? '') === 'debug') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Category not found\n";
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

    echo "Category not found";
    exit;
}

if (!$slug) {
    category_not_found_debug('missing_slug', $slug);
}

$categoriesRepository = new StoreCategoriesRepository();
$productsRepository = new StoreProductsRepository();
$ownerId = AvomealContext::ownerId();
$siteKey = SiteContext::siteKey();

$category = $categoriesRepository->getPublicBySlug($slug, $ownerId, $siteKey);

if (!$category || ($category->status ?? null) !== StoreCategoriesRepository::STATUS_ACTIVE) {
    $rawCategory = $categoriesRepository->getBySlug($slug, $ownerId, $siteKey);
    $reason = $rawCategory
        ? 'public_visibility_or_status_failed_for_category_id_' . (int)$rawCategory->id
        : 'slug_owner_site_lookup_failed';

    $debug = method_exists($categoriesRepository, 'debugPublicCategoryLookup')
        ? $categoriesRepository->debugPublicCategoryLookup($slug, $ownerId, $siteKey)
        : [];

    category_not_found_debug($reason, $slug, $ownerId, $siteKey, $debug);
}

$products = $productsRepository->getPublicByCategory((int)$category->id, 120, $ownerId, $siteKey);

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
