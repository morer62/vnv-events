<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$templatePath = 'panel/level1/planner-hub/management/storage/containers/index.twig';
$absolutePath = dirname(__DIR__) . '/src/views/' . $templatePath;
$source = file_get_contents($absolutePath);

if ($source === false) {
    fwrite(STDERR, "FAIL: unable to read {$templatePath}\n");
    exit(1);
}

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/src/views'));
foreach (['path', 'asset', 'asset_for', 'trans', 'url', 'csrf_token', 'get_csrf'] as $functionName) {
    $twig->addFunction(new Twig\TwigFunction($functionName, static fn (...$arguments): string => '/qa-path'));
}

try {
    $twig->parse($twig->tokenize(new Twig\Source($source, $templatePath)));
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL: Twig parse error: {$error->getMessage()}\n");
    exit(1);
}

$expectations = [
    'title size control' => 'id="tagTitleSize"',
    'title size overrides the panel heading style' => 'font-size: var(--tag-title-size) !important',
    'QR size control' => 'id="tagQrSize"',
    'table width control' => 'id="tagTableWidth"',
    'item text size control' => 'id="tagItemsSize"',
    'left alignment control' => 'data-tag-align="left"',
    'center alignment control' => 'data-tag-align="center"',
    'right alignment control' => 'data-tag-align="right"',
    'shared preview clone used for print' => 'const previewClone = sourcePreview.cloneNode(true);',
    'print uses the configured preview' => '${previewClone.outerHTML}',
    'QR converted to printable image' => "qrCanvas.toDataURL('image/png')",
];

foreach ($expectations as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: missing {$label}\n");
        exit(1);
    }
}

foreach (['id="containerUrl"', 'id="currentDate"', 'window.containerPrintItems'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fwrite(STDERR, "FAIL: obsolete separately-rendered label content remains: {$forbidden}\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: storage label editor Twig and print-preview contract verified.\n");
