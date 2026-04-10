<?php

declare(strict_types=1);

$envPath = __DIR__ . '/../.env';
$env = @file_get_contents($envPath);
if ($env === false) {
    fwrite(STDERR, ".env not found at {$envPath}\n");
    exit(1);
}

if (!preg_match('/^DATABASE_URL=(.+)$/m', $env, $m)) {
    fwrite(STDERR, "DATABASE_URL not found in .env\n");
    exit(1);
}

$url = trim($m[1]);

$host = '127.0.0.1';
$port = '3306';
$db = '';
$user = 'root';
$pass = '';

if (preg_match('/host=([^;]+)/', $url, $mm)) $host = $mm[1];
if (preg_match('/port=([^;]+)/', $url, $mm)) $port = $mm[1];
if (preg_match('/dbname=([^;]+)/', $url, $mm)) $db = $mm[1];
if (preg_match('/user=([^;]+)/', $url, $mm)) $user = $mm[1];
if (preg_match('/password=([^;]*)/', $url, $mm)) $pass = $mm[1];

if ($db === '') {
    fwrite(STDERR, "dbname could not be parsed from DATABASE_URL\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$tables = ['user_cards', 'store_orders', 'store_coupons'];
foreach ($tables as $t) {
    echo "\n== {$t} ==\n";
    try {
        $rows = $pdo->query("DESCRIBE `{$t}`")->fetchAll();
        foreach ($rows as $r) {
            $default = array_key_exists('Default', $r) ? $r['Default'] : null;
            echo ($r['Field'] ?? '') . "\t" . ($r['Type'] ?? '') . "\t" . ($r['Null'] ?? '') . "\t" . ($default === null ? 'NULL' : (string)$default) . "\n";
        }
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

