<?php

function vnv_level1_cms_proxy(string $relativePath = ''): void
{
    $relativePath = trim($relativePath, '/');
    $target = dirname(__DIR__, 2) . '/level6/cms';
    if ($relativePath !== '') {
        $target .= '/' . $relativePath;
    }

    require $target . '/index.php';
}
