<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AiVideoRenderService;

$out = __DIR__ . '/../test-results/video-render';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

$srt = "1\n00:00:00,000 --> 00:00:02,500\nCreate content people remember\n\n"
    . "2\n00:00:02,500 --> 00:00:05,000\nMove with purpose and energy\n";
$service = new AiVideoRenderService();
$method = new ReflectionMethod($service, 'createKineticAss');
$method->setAccessible(true);
$method->invoke($service, $srt, $out . '/kinetic.ass', 1080, 1920, ['remember', 'energy']);

echo $out . '/kinetic.ass';
