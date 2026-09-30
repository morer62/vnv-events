<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$name=(string)($argv[1]??'');
if(!preg_match('/^20\d{6}_[a-z0-9_]+\.sql$/',$name)){fwrite(STDERR,"Provide a migration filename from db/.\n");exit(2);}
$root=realpath(dirname(__DIR__).'/db');$file=realpath($root.DIRECTORY_SEPARATOR.$name);
if(!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)){fwrite(STDERR,"Migration not found.\n");exit(2);}
try{$pdo=new PDO((string)$_ENV['DATABASE_URL']);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec((string)file_get_contents($file));fwrite(STDOUT,$name." applied successfully.\n");}
catch(Throwable $e){fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");exit(1);}
