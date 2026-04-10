<?php

/**
 * Autopay Renewals Cron Job
 * 
 * This script should run daily via Windows Task Scheduler or cron
 * It processes automatic membership renewals for users with autopay enabled
 * 
 * Schedule: Daily at 2:00 AM
 * Command: php C:\xampp\htdocs\vnv-venue\src\cron\autopay-renewals.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use App\Services\AutopayService;

// Start logging
$logFile = __DIR__ . '/../../.logs/autopay_' . date('Y-m-d') . '.log';
$startTime = microtime(true);

function logMessage(string $message, string $logFile): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo $logEntry;
}

logMessage("=== Autopay Renewals Cron Job Started ===", $logFile);

try {
    $autopayService = new AutopayService();
    
    // Process new renewals
    logMessage("Processing new renewals...", $logFile);
    $renewalResults = $autopayService->processAllRenewals();
    logMessage("Renewals processed: {$renewalResults['processed']}, Successful: {$renewalResults['successful']}, Failed: {$renewalResults['failed']}", $logFile);
    
    // Process retries
    logMessage("Processing retry queue...", $logFile);
    $retryResults = $autopayService->processRetries();
    logMessage("Retries processed: {$retryResults['processed']}, Successful: {$retryResults['successful']}, Failed: {$retryResults['failed']}, Abandoned: {$retryResults['abandoned']}", $logFile);
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    logMessage("=== Autopay Renewals Cron Job Completed in {$executionTime}s ===", $logFile);
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage(), $logFile);
    logMessage("Stack trace: " . $e->getTraceAsString(), $logFile);
    exit(1);
}

exit(0);
