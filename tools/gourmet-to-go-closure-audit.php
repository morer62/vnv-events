<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$pdo = new PDO((string)$_ENV['DATABASE_URL'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET NAMES 'utf8mb4'");
$owner = 2;
$site = 'vnvevents';

$fetchAll = static function (string $sql, array $params = []) use ($pdo): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$dessertRows = $fetchAll(
    "SELECT id,name,slug,sku,status,is_public,price,main_image,created_at,updated_at
     FROM store_products
     WHERE id_owner=:owner AND site_key=:site
       AND (slug LIKE 'dessert-box%' OR name='Dessert Box')
     ORDER BY id",
    ['owner' => $owner, 'site' => $site]
);

$duplicateSlugs = $fetchAll(
    "SELECT id_owner,site_key,slug,COUNT(*) total,GROUP_CONCAT(id ORDER BY id) ids
     FROM store_products
     GROUP BY id_owner,site_key,slug
     HAVING COUNT(*)>1
     ORDER BY total DESC,slug"
);

$mainImages = $fetchAll(
    "SELECT name item,slug,main_image image_url
     FROM store_products
     WHERE id_owner=:owner AND site_key=:site
       AND slug IN ('appetizer-box','appetizer-box-breakfast-brunch','dessert-box','brazo-gitano')
     ORDER BY FIELD(slug,'appetizer-box','appetizer-box-breakfast-brunch','dessert-box','brazo-gitano')",
    ['owner' => $owner, 'site' => $site]
);

$choiceImages = $fetchAll(
    "SELECT DISTINCT c.name item,c.slug,c.image_url
     FROM store_product_option_choices c
     JOIN store_product_option_groups g ON g.id=c.id_group
     WHERE g.id_owner=:owner AND g.site_key=:site
       AND g.choice_set_key IN ('appetizer-varieties','dessert-varieties')
       AND c.status='ACTIVE'
     ORDER BY FIELD(g.choice_set_key,'appetizer-varieties','dessert-varieties'),c.sort_order,c.id",
    ['owner' => $owner, 'site' => $site]
);

$root = dirname(__DIR__) . '/public';
$inspectImage = static function (array $row) use ($root): array {
    $url = (string)($row['image_url'] ?? '');
    $path = $url !== '' ? $root . '/' . ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/') : '';
    $exists = $path !== '' && is_file($path);
    $dimensions = $exists ? @getimagesize($path) : false;
    return $row + [
        'local_path' => $path,
        'exists_local' => $exists,
        'width' => is_array($dimensions) ? (int)$dimensions[0] : null,
        'height' => is_array($dimensions) ? (int)$dimensions[1] : null,
        'bytes' => $exists ? filesize($path) : null,
        'md5' => $exists ? md5_file($path) : null,
    ];
};

$images = array_map($inspectImage, array_merge($mainImages, $choiceImages));
$hashGroups = [];
foreach ($images as $image) {
    if (!$image['md5']) continue;
    $hashGroups[$image['md5']][] = $image['item'];
}
$duplicateImages = array_filter($hashGroups, static fn(array $items): bool => count($items) > 1);

$ensaimadaAddons = $fetchAll(
    "SELECT r.id,r.id_product,r.id_related_product,p.name,p.slug,p.status,p.is_public
     FROM store_product_relationships r
     JOIN store_products p ON p.id=r.id_related_product
     WHERE (p.name LIKE '%Ensaimada%' OR p.slug LIKE '%ensaimada%')"
);

$indexes = $fetchAll("SHOW INDEX FROM store_products");
$slugIndexes = array_values(array_filter($indexes, static fn(array $index): bool => str_contains(strtolower((string)$index['Column_name']), 'slug')));

echo json_encode([
    'dessert_rows' => $dessertRows,
    'duplicate_slugs' => $duplicateSlugs,
    'images' => $images,
    'duplicate_image_hashes' => $duplicateImages,
    'ensaimada_addons' => $ensaimadaAddons,
    'slug_indexes' => $slugIndexes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
