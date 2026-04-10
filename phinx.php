<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = new PDO($_ENV["DATABASE_URL"]);
$query = str_replace("mysql:", "", $_ENV["DATABASE_URL"]);
parse_str(str_replace(";", "&", $query), $params);
$dbname = $params['dbname'] ?? null;

return
[
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'name' => $dbname,
            'connection' => $pdo
        ]
    ],
    'version_order' => 'creation'
];
