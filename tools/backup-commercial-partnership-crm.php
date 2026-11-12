<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$databaseUrl = (string)($_ENV['DATABASE_URL'] ?? '');
if ($databaseUrl === '') {
    throw new RuntimeException('DATABASE_URL is not configured.');
}

$pdo = new PDO($databaseUrl, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tables = [
    'commercial_organizations', 'commercial_contacts', 'commercial_opportunities',
    'commercial_projects', 'commercial_sponsorship_inventory', 'commercial_sponsor_deals',
    'commercial_activation_orders', 'commercial_proposals', 'commercial_invoice_links',
    'commercial_support_grants', 'commercial_activities', 'commercial_commissions',
    'commercial_consents', 'commercial_media_assets', 'commercial_audit_logs',
];
$snapshot = [
    'schema' => 'vnv-commercial-partnership-crm-v1',
    'created_at' => gmdate('c'),
    'owner_id' => 2,
    'site_key' => 'vnvevents',
    'tables' => [],
];
foreach ($tables as $table) {
    $statement = $pdo->prepare("SELECT * FROM `{$table}` WHERE id_owner=:owner AND site_key=:site ORDER BY id");
    $statement->execute([':owner' => 2, ':site' => 'vnvevents']);
    $snapshot['tables'][$table] = $statement->fetchAll();
}

$directory = $root . '/storage/backups/commercial-crm';
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create the protected CRM backup directory.');
}
$filename = $directory . '/commercial-crm-' . gmdate('Ymd-His') . '.json';
$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (file_put_contents($filename, $json, LOCK_EX) === false) {
    throw new RuntimeException('Could not write the CRM backup.');
}
@chmod($filename, 0640);
echo 'Commercial CRM backup written: ' . $filename . PHP_EOL;
