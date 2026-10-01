<?php

if (empty($GLOBALS['vnv_gourmet_canonical'])) {
    header('Location: /catering-delivery', true, 301);
    exit;
}

use App\Repositories\Connection;
use App\Repositories\StoreProductsRepository;
use App\Services\GourmetExpressService;
use App\Utils\AvomealContext;
use App\Utils\SiteContext;
use App\Utils\TemplateResponse;

$ownerId = AvomealContext::ownerId();
$siteKey = SiteContext::siteKey();
$productsRepo = new StoreProductsRepository();
$products = $productsRepo->getActivePublic(250, $ownerId, $siteKey);

$groups = [
    'bundles' => ['title' => 'Curated Bundles', 'products' => []],
    'main' => ['title' => 'Main Courses', 'products' => []],
    'desserts' => ['title' => 'Desserts', 'products' => []],
    'appetizers' => ['title' => 'Appetizers', 'products' => []],
    'seasonal' => ['title' => 'Seasonal', 'products' => []],
];

try {
    $db = new Connection();
    foreach ($products as $product) {
        if ((string)($product->site_key ?? '') !== 'vnvevents'
            || strtoupper((string)($product->fulfillment_type ?? '')) !== 'DELIVERY'
            || strtoupper((string)($product->purchase_mode ?? '')) !== 'DIRECT'
            || !(int)($product->allow_immediate_payment ?? 0)
            || strtoupper((string)($product->product_role ?? 'MAIN')) === 'ADDON'
            || (int)($product->is_addon_only ?? 0) === 1) {
            continue;
        }

        $db->query("SELECT LOWER(GROUP_CONCAT(sc.name SEPARATOR ' ')) AS category_names
            FROM store_products_categories spc
            INNER JOIN store_categories sc ON sc.id=spc.id_category
            WHERE spc.id_product=:product");
        $db->bind(':product', (int)$product->id, \PDO::PARAM_INT);
        $categoryText = strtolower((string)($db->fetchOne()->category_names ?? ''));
        $haystack = $categoryText . ' ' . strtolower((string)$product->name);

        if (strtoupper((string)($product->product_role ?? 'MAIN')) === 'BUNDLE') {
            $groups['bundles']['products'][] = $product;
        } elseif (preg_match('/seasonal|holiday|thanksgiving|christmas|valentine|easter|nochebuena/', $haystack)) {
            $groups['seasonal']['products'][] = $product;
        } elseif (preg_match('/dessert|cake|cannoli|tiramisu|sweet|flan|cookie|brownie/', $haystack)) {
            $groups['desserts']['products'][] = $product;
        } elseif (preg_match('/appetizer|starter|finger|bite|empanada|croquette|bruschetta/', $haystack)) {
            $groups['appetizers']['products'][] = $product;
        } else {
            $groups['main']['products'][] = $product;
        }
    }
} catch (Throwable $e) {
    error_log('[VNV Gourmet To Go] Catalog grouping failed: ' . $e->getMessage());
}

echo TemplateResponse::render(__DIR__ . '/index.twig', [
    'groups' => $groups,
    'gourmet_settings' => (new GourmetExpressService())->settings($ownerId, $siteKey),
    'to_go_product_count' => array_sum(array_map(static fn($group) => count($group['products']), $groups)),
]);
