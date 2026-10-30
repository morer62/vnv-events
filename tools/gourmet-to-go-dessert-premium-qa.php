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
    $statement = $pdo->prepare('SELECT id,name,status,is_public,short_description,description,main_image FROM store_products WHERE id_owner=2 AND site_key=\'vnvevents\' AND slug=:slug LIMIT 1');
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
$appetizerVariation = $variation((int)$appetizer['id'], 48);
$appetizerGroups = $options->groupsForProduct(2, 'vnvevents', (int)$appetizer['id']);
$appetizerChoices = $appetizerGroups[0]['choices'] ?? [];
$premium = array_values(array_filter($appetizerChoices, static fn(array $choice): bool => (float)$choice['price_modifier'] > 0));
$standard = array_values(array_filter($appetizerChoices, static fn(array $choice): bool => (float)$choice['price_modifier'] === 0.0));
$assert(count($premium) >= 3, 'Expected three premium slider choices.');
$assert(count($standard) >= 2, 'Expected standard appetizer choices.');
$assert((int)($appetizerGroups[0]['allow_choice_repeats'] ?? 0) === 1, 'Expected appetizer choices to allow repeats.');
$appetizerSnapshot = $options->validate(2, 'vnvevents', (int)$appetizer['id'], (int)$appetizerVariation['id'], [
    'option_groups' => [[
        'group_id' => (int)$appetizerGroups[0]['id'],
        'selected_choice_quantities' => [(int)$premium[0]['id'] => 3, (int)$standard[0]['id'] => 1],
    ]],
]);
$appetizerModifier = (float)$appetizerSnapshot[0]['price_modifier_total'];
$appetizerTotal = (float)$appetizerVariation['price'] + $appetizerModifier;
$assert((int)$appetizerSnapshot[0]['required_count'] === 4, 'Expected every appetizer size to require four selections.');
$assert((int)$appetizerSnapshot[0]['pieces_per_option'] === 12, 'Expected twelve pieces per selection in the 48-piece box.');
$assert((int)$appetizerSnapshot[0]['selections'][0]['quantity'] === 3, 'Expected the repeated premium variety to retain quantity three.');
$assert((int)$appetizerSnapshot[0]['selections'][0]['pieces'] === 36, 'Expected the repeated premium variety to total 36 pieces.');
$assert($appetizerModifier === 45.0, 'Expected three premium blocks to add $45.');
$assert($appetizerTotal === 160.0, 'Expected the 48-piece box with three premium blocks to total $160.');

$dessert = $product('dessert-box');
$dessertVariationCount = $pdo->prepare('SELECT COUNT(*) FROM store_product_variations WHERE id_product=:product AND status=\'ACTIVE\'');
$dessertVariationCount->execute(['product' => (int)$dessert['id']]);
$assert((int)$dessertVariationCount->fetchColumn() === 3, 'Expected the 24-, 48- and 64-piece Dessert Box variations to remain active.');
$dessertVariation = $variation((int)$dessert['id'], 48);
$dessertGroups = $options->groupsForProduct(2, 'vnvevents', (int)$dessert['id']);
$dessertChoices = $dessertGroups[0]['choices'] ?? [];
$assert(count($dessertChoices) === 6, 'Expected six Dessert Box varieties.');
$assert((int)($dessertGroups[0]['allow_choice_repeats'] ?? 0) === 1, 'Expected Dessert Box choices to allow repeats.');
$dessertChoiceQuantities = [(int)$dessertChoices[0]['id'] => 3, (int)$dessertChoices[1]['id'] => 1];
$dessertSnapshot = $options->validate(2, 'vnvevents', (int)$dessert['id'], (int)$dessertVariation['id'], [
    'option_groups' => [[
        'group_id' => (int)$dessertGroups[0]['id'],
        'selected_choice_quantities' => $dessertChoiceQuantities,
    ]],
]);
$assert((int)$dessertSnapshot[0]['required_count'] === 4, 'Expected the 48-piece Dessert Box to require four choices.');
$assert((int)$dessertSnapshot[0]['pieces_per_option'] === 12, 'Expected twelve pieces of each dessert choice.');
$assert((int)$dessertSnapshot[0]['selections'][0]['quantity'] === 3, 'Expected repeated Dessert Box variety quantity three.');
$assert((int)$dessertSnapshot[0]['selections'][0]['pieces'] === 36, 'Expected repeated Dessert Box variety to total 36 pieces.');
$assert((float)$dessertVariation['price'] === 89.0, 'Expected the 48-piece Dessert Box to cost $89.');
$dessertSmallVariation = $variation((int)$dessert['id'], 24);
$dessertSmallSnapshot = $options->validate(2, 'vnvevents', (int)$dessert['id'], (int)$dessertSmallVariation['id'], [
    'option_groups' => [[
        'group_id' => (int)$dessertGroups[0]['id'],
        'selected_choice_quantities' => $dessertChoiceQuantities,
    ]],
]);
$assert((int)$dessertSmallSnapshot[0]['required_count'] === 4, 'Expected the 24-piece Dessert Box to require four choices.');
$assert((int)$dessertSmallSnapshot[0]['pieces_per_option'] === 6, 'Expected six pieces per selection in the 24-piece Dessert Box.');
$assert((int)$dessertSmallSnapshot[0]['selections'][0]['pieces'] === 18, 'Expected repeated small Dessert Box variety to total 18 pieces.');
$dessertLargeVariation = $variation((int)$dessert['id'], 64);
$dessertLargeSnapshot = $options->validate(2, 'vnvevents', (int)$dessert['id'], (int)$dessertLargeVariation['id'], [
    'option_groups' => [[
        'group_id' => (int)$dessertGroups[0]['id'],
        'selected_choice_quantities' => $dessertChoiceQuantities,
    ]],
]);
$assert((int)$dessertLargeSnapshot[0]['required_count'] === 4, 'Expected the 64-piece Dessert Box to require four choices.');
$assert((int)$dessertLargeSnapshot[0]['pieces_per_option'] === 16, 'Expected sixteen pieces per selection in the 64-piece Dessert Box.');
$assert((float)$dessertLargeVariation['price'] === 115.0, 'Expected the 64-piece Dessert Box to cost $115.');
$dessertRows = $pdo->prepare('SELECT COUNT(*) FROM store_products WHERE id_owner=2 AND site_key=\'vnvevents\' AND slug=\'dessert-box\'');
$dessertRows->execute();
$assert((int)$dessertRows->fetchColumn() === 1, 'Expected one canonical dessert-box slug.');
$oldDessertProfile = $pdo->prepare('SELECT COUNT(*) FROM store_product_food_profiles WHERE id_product=:product');
$oldDessertProfile->execute(['product' => (int)$dessert['id']]);
$assert((int)$oldDessertProfile->fetchColumn() === 0, 'Expected the obsolete Dessert Box food profile to be removed.');

foreach (['cream-profiteroles', 'pastry-cream-tartlets'] as $legacySlug) {
    $legacy = $product($legacySlug);
    $assert($legacy['status'] === 'INACTIVE' && (int)$legacy['is_public'] === 0, "Expected {$legacySlug} to be inactive and private.");
}

$ensaimada = $product('ensaimada-family-recipe');
$assert($ensaimada['status'] === 'INACTIVE' && (int)$ensaimada['is_public'] === 0, 'Expected Ensaimada to be inactive and private.');
$bocaCount = $pdo->query("SELECT COUNT(*) FROM store_products WHERE id_owner=2 AND site_key='vnvevents' AND slug='boca-abierta'");
$assert((int)$bocaCount->fetchColumn() === 0, 'Expected the unapproved Boca Abierta draft to be absent.');

$brazo = $product('brazo-gitano');
$assert($brazo['short_description'] === 'Light sponge rolled with pastry cream, topped with fresh strawberries.', 'Unexpected Brazo Gitano short description.');
$assert(strip_tags((string)$brazo['description']) === 'Our family-recipe brazo gitano: light sponge rolled with pastry cream and finished with fresh strawberries. Slice and serve, no heating needed. Keep refrigerated until serving.', 'Unexpected Brazo Gitano long description.');
$assert($brazo['main_image'] === '/assets/images/gourmet-to-go/gtg-brazo-gitano.webp', 'Unexpected Brazo Gitano image path.');

echo json_encode([
    'repeatable_appetizer' => ['pieces' => 48, 'selections' => '3 + 1', 'base' => (float)$appetizerVariation['price'], 'modifier' => $appetizerModifier, 'total' => $appetizerTotal],
    'dessert_box' => ['choices' => count($dessertChoices), 'sizes' => [24, 48, 64], 'required' => (int)$dessertSnapshot[0]['required_count'], 'pieces_each_64' => (int)$dessertLargeSnapshot[0]['pieces_per_option'], 'price_64' => (float)$dessertLargeVariation['price']],
    'legacy_products' => 'inactive/removed as specified',
    'anonymous_cleanup' => 'one Dessert Box slug; obsolete food profile removed',
    'brazo_gitano' => 'approved copy and image',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
