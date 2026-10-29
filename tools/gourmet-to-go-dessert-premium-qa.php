<?php

declare(strict_types=1);

use App\Services\StoreProductOptionsService;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->load();

$pdo = new PDO((string)$_ENV['DATABASE_URL'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET NAMES 'utf8mb4'");
$options = new StoreProductOptionsService();

$product = static function (string $slug) use ($pdo): array {
    $statement = $pdo->prepare('SELECT id,name,status,is_public FROM store_products WHERE id_owner=2 AND site_key=\'vnvevents\' AND slug=:slug LIMIT 1');
    $statement->execute(['slug' => $slug]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Missing product: {$slug}");
    return $row;
};
$variation = static function (int $productId, int $pieces) use ($pdo): array {
    $statement = $pdo->prepare('SELECT id,price FROM store_product_variations WHERE id_product=:product AND piece_count=:pieces AND status=\'ACTIVE\' LIMIT 1');
    $statement->execute(['product' => $productId, 'pieces' => $pieces]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Missing {$pieces}-piece variation for product {$productId}");
    return $row;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$appetizer = $product('appetizer-box');
$breakfast = $product('appetizer-box-breakfast-brunch');
$assert($appetizer['name'] === 'Appetizer Box — All Day', 'Expected the All Day appetizer product name.');
$assert($breakfast['name'] === 'Appetizer Box — Breakfast & Brunch', 'Expected the Breakfast & Brunch product name.');
$appetizerVariation = $variation((int)$appetizer['id'], 45);
$appetizerGroups = $options->groupsForProduct(2, 'vnvevents', (int)$appetizer['id']);
$appetizerChoices = $appetizerGroups[0]['choices'] ?? [];
$premium = array_values(array_filter($appetizerChoices, static fn(array $choice): bool => (float)$choice['price_modifier'] > 0));
$standard = array_values(array_filter($appetizerChoices, static fn(array $choice): bool => (float)$choice['price_modifier'] === 0.0));
$assert(count($premium) >= 3, 'Expected three premium slider choices.');
$assert(count($standard) >= 2, 'Expected standard appetizer choices.');
$appetizerSnapshot = $options->validate(2, 'vnvevents', (int)$appetizer['id'], (int)$appetizerVariation['id'], [
    'option_groups' => [[
        'group_id' => (int)$appetizerGroups[0]['id'],
        'selected_choice_ids' => [(int)$premium[0]['id'], (int)$standard[0]['id'], (int)$standard[1]['id']],
    ]],
]);
$appetizerModifier = (float)$appetizerSnapshot[0]['price_modifier_total'];
$appetizerTotal = (float)$appetizerVariation['price'] + $appetizerModifier;
$assert($appetizerModifier === 15.0, 'Expected one premium block to add $15.');
$assert($appetizerTotal === 130.0, 'Expected 45-piece premium box total to be $130.');

$dessert = $product('dessert-box');
$dessertVariationCount = $pdo->prepare('SELECT COUNT(*) FROM store_product_variations WHERE id_product=:product AND status=\'ACTIVE\'');
$dessertVariationCount->execute(['product' => (int)$dessert['id']]);
$assert((int)$dessertVariationCount->fetchColumn() === 2, 'Expected only the 24- and 48-piece Dessert Box variations to remain active.');
$dessertVariation = $variation((int)$dessert['id'], 48);
$dessertGroups = $options->groupsForProduct(2, 'vnvevents', (int)$dessert['id']);
$dessertChoices = $dessertGroups[0]['choices'] ?? [];
$assert(count($dessertChoices) === 6, 'Expected six Dessert Box varieties.');
$dessertChoiceIds = array_map(static fn(array $choice): int => (int)$choice['id'], array_slice($dessertChoices, 0, 4));
$dessertSnapshot = $options->validate(2, 'vnvevents', (int)$dessert['id'], (int)$dessertVariation['id'], [
    'option_groups' => [[
        'group_id' => (int)$dessertGroups[0]['id'],
        'selected_choice_ids' => $dessertChoiceIds,
    ]],
]);
$assert((int)$dessertSnapshot[0]['required_count'] === 4, 'Expected the 48-piece Dessert Box to require four choices.');
$assert((int)$dessertSnapshot[0]['pieces_per_option'] === 12, 'Expected twelve pieces of each dessert choice.');
$assert((float)$dessertVariation['price'] === 89.0, 'Expected the 48-piece Dessert Box to cost $89.');

foreach (['cream-profiteroles', 'pastry-cream-tartlets'] as $legacySlug) {
    $legacy = $product($legacySlug);
    $assert($legacy['status'] === 'INACTIVE' && (int)$legacy['is_public'] === 0, "Expected {$legacySlug} to be inactive and private.");
}

echo json_encode([
    'premium_appetizer' => ['base' => (float)$appetizerVariation['price'], 'modifier' => $appetizerModifier, 'total' => $appetizerTotal],
    'dessert_box' => ['choices' => count($dessertChoices), 'required' => (int)$dessertSnapshot[0]['required_count'], 'pieces_each' => (int)$dessertSnapshot[0]['pieces_per_option'], 'price' => (float)$dessertVariation['price']],
    'legacy_products' => 'inactive',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
