<?php

require __DIR__ . '/../vendor/autoload.php';

global $argv;

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}catch (Exception $e) {
    echo "Error loading environment variables: " . $e->getMessage() . "\n";
}


if (count($argv) < 2) {
    echo "Usage: php commands.php <command-name> [options]\n";
    exit(1);
}

$commandName = $argv[1];

// Convert command name to class name (e.g., 'create-sitemap' -> 'CreateSiteMap')
$className = str_replace(' ', '', ucwords(str_replace('-', ' ', $commandName)));
$fullClassName = "App\\Commands\\{$className}";

// Check if the command class exists
if (!class_exists($fullClassName)) {
    echo "Command '{$commandName}' not found. Class '{$fullClassName}' does not exist.\n";
    exit(1);
}

// Instantiate and run the command
try {
    $commandInstance = new $fullClassName();
    $commandInstance->run();
} catch (Exception $e) {
    echo "Error running command: " . $e->getMessage() . "\n";
    exit(1);
}