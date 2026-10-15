<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$databaseUrl=(string)($_ENV['DATABASE_URL']??'');
if($databaseUrl==='')throw new RuntimeException('DATABASE_URL is not configured.');
$pdo=new PDO($databaseUrl,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/db/20261015_mochi_cloud_integration.sql'));
echo "MOCHI_VNV_MIGRATION_OK\n";
