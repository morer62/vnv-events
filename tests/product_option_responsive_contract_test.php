<?php

declare(strict_types=1);

$template = file_get_contents(dirname(__DIR__) . '/src/views/public/pages/product/index.twig');
if ($template === false) {
    throw new RuntimeException('Product template could not be read.');
}

foreach ([
    'repeat(auto-fit,minmax(min(100%,360px),1fr))' => 'Option cards do not adapt to the available product-column width.',
    '.pp-option-stepper{grid-column:1/-1' => 'Mobile quantity controls do not span the card width.',
    '.pp-option-choice>span:nth-child(2){min-width:0}' => 'Option copy can force the quantity selector outside the card.',
] as $needle => $message) {
    if (!str_contains($template, $needle)) {
        throw new RuntimeException($message);
    }
}

echo "Product option responsive contract passed.\n";
