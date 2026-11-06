<?php

declare(strict_types=1);

use App\Services\GourmetOfflineConversionExporter;

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$apply = in_array('--apply', $argv, true);
$ownerId = 2;
$output = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--owner=')) {
        $ownerId = max(1, (int)substr($argument, 8));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = trim(substr($argument, 9));
    }
}

if ($apply && $output === '') {
    fwrite(STDERR, "--apply requires an explicit --output=path.csv\n");
    exit(2);
}

$exporter = new GourmetOfflineConversionExporter();
$rows = $exporter->pending($ownerId);
$csv = GourmetOfflineConversionExporter::csv($rows);

if ($output !== '') {
    $directory = dirname($output);
    if (!is_dir($directory) || !is_writable($directory)) {
        fwrite(STDERR, "Output directory is not writable: {$directory}\n");
        exit(3);
    }
    if (file_put_contents($output, $csv, LOCK_EX) === false) {
        fwrite(STDERR, "Could not write export file.\n");
        exit(4);
    }
} else {
    fwrite(STDOUT, $csv);
}

if ($apply && $rows !== []) {
    $exporter->markExported($ownerId, $rows, 'manual-' . gmdate('Ymd-His'));
}

fwrite(STDERR, sprintf("%s: %d conversion row(s).%s\n", $apply ? 'APPLIED' : 'DRY RUN', count($rows), $output !== '' ? " File: {$output}" : ''));
