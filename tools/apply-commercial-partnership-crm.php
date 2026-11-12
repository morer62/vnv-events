<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

$migrationPath = $root . '/db/migrations/20261112150000_create_commercial_partnership_crm.php';
$migration = (string)file_get_contents($migrationPath);
if (!preg_match("/<<<'SQL'\R(.*?)\RSQL\);/s", $migration, $matches)) {
    throw new RuntimeException('Could not read the commercial CRM schema from its migration.');
}

$databaseUrl = (string)($_ENV['DATABASE_URL'] ?? '');
if ($databaseUrl === '') {
    throw new RuntimeException('DATABASE_URL is not configured.');
}

$pdo = new PDO($databaseUrl, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
$pdo->exec("SET NAMES 'utf8mb4'");
$pdo->exec($matches[1]);

$required = [
    'commercial_organizations', 'commercial_contacts', 'commercial_opportunities',
    'commercial_projects', 'commercial_sponsorship_inventory', 'commercial_sponsor_deals',
    'commercial_activation_orders', 'commercial_proposals', 'commercial_invoice_links',
    'commercial_support_grants', 'commercial_activities', 'commercial_commissions',
    'commercial_consents', 'commercial_media_assets', 'commercial_audit_logs',
];

$check = $pdo->prepare('SHOW TABLES LIKE :table');
foreach ($required as $table) {
    $check->execute([':table' => $table]);
    if (!$check->fetchColumn()) {
        throw new RuntimeException('Schema verification failed for table: ' . $table);
    }
}

echo 'Commercial partnership CRM schema is ready (' . count($required) . " tables verified).\n";
