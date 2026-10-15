<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use App\Repositories\Connection;

$apply = in_array('--apply', $argv, true);
$oldCopy = 'Every Station includes Hot breakfast items (scrambled eggs, bacon, sausages, roasted potatoes) and Fresh bread &amp; pastries';
$newCopy = 'Every station includes scrambled eggs and sausages. Bacon, roasted potatoes, fresh bread and pastries are optional add-ons available upon request.';

$db = new Connection();
$db->query("SELECT id, description FROM store_products WHERE slug = 'brunch-specials' LIMIT 1");
$product = $db->fetchOne();

if (!$product) {
    throw new RuntimeException('The brunch-specials product was not found.');
}

$description = (string) $product->description;
if (!str_contains($description, $oldCopy)) {
    if (str_contains($description, $newCopy)) {
        echo "Brunch menu copy is already current.\n";
        exit(0);
    }
    throw new RuntimeException('The expected brunch menu sentence was not found; no data was changed.');
}

if (!$apply) {
    echo "Dry run: brunch menu copy requires the approved update. Run with --apply.\n";
    exit(0);
}

$backupDirectory = dirname(__DIR__) . '/backups/database';
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Could not create the database backup directory.');
}
$backupPath = $backupDirectory . '/brunch-specials-before-' . date('Ymd-His') . '.json';
$backup = json_encode(
    ['id' => (int) $product->id, 'description' => $description],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ($backup === false || file_put_contents($backupPath, $backup . PHP_EOL) === false) {
    throw new RuntimeException('Could not back up the brunch menu before updating it.');
}

$updated = str_replace($oldCopy, $newCopy, $description);
$db->query('UPDATE store_products SET description = :description WHERE id = :id');
$db->bind(':description', $updated);
$db->bind(':id', (int) $product->id);
$db->execute();

echo "Brunch menu copy updated successfully.\n";
