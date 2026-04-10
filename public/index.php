<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Kernel;

session_start();
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$app = new Kernel();
$app->run();
