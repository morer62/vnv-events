<?php

use App\Utils\CSRF;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

// 🔥 Ruta de descarga directa
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($_SERVER['REQUEST_URI'], 'panel/text-voice/download') !== false) {
    if (!isset($_SESSION['audioPath']) || !file_exists($_SESSION['audioPath'])) {
        echo "No audio generated.";
        exit;
    }

    $path = $_SESSION['audioPath'];
    header('Content-Type: audio/mpeg');
    header('Content-Disposition: attachment; filename="voice.mp3"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// 🎤 Vista principal
$router->get(function () {
    return TemplateResponse::render(__DIR__ . "/index.twig", [
        'audioGenerated' => isset($_SESSION['audioPath']),
        'originalText' => $_SESSION['originalText'] ?? null,
    ]);
});

// 🔁 Procesar formulario
$router->post(function () {
    CSRF::validateCSRF();

    $text = $_POST['textInput'] ?? '';
    if (empty($text)) {
        MessageUtil::setMessage("Please enter some text.");
        LocationUtils::redirectInternal("panel/text-voice/home");
        return;
    }

    $finalPath = processTextWithPauses($text);

    if ($finalPath && file_exists($finalPath)) {
        $_SESSION['audioPath'] = $finalPath;
        $_SESSION['originalText'] = $text;
        MessageUtil::setMessage("Voice generated successfully.");
    } else {
        MessageUtil::setMessage("Error generating voice.");
    }

    LocationUtils::redirectInternal("panel/text-voice/home");
});

// 🔊 Procesamiento del texto y pausas
function processTextWithPauses($text) {
    $parts = [];
    $pattern = '/<pausa sec="(\d+)">(.*?)<\/pausa>/is';
    $lastPos = 0;

    preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $i => $match) {
        $start = $match[1];
        $before = trim(substr($text, $lastPos, $start - $lastPos));
        if ($before) $parts[] = ['text' => $before];

        $pause = (int)$matches[1][$i][0];
        $content = trim($matches[2][$i][0]);
        $parts[] = ['text' => $content, 'pause' => $pause];

        $lastPos = $start + strlen($match[0]);
    }

    $remaining = trim(substr($text, $lastPos));
    if ($remaining) $parts[] = ['text' => $remaining];

    $allAudioFiles = [];

    foreach ($parts as $i => $part) {
        $textPath = "/tmp/audio_part_{$i}.mp3";
        if (!generateOpenAIAudio($part['text'], $textPath)) return null;
        $allAudioFiles[] = $textPath;

        if (isset($part['pause'])) {
            $silencePath = "/tmp/silence_{$i}.mp3";
            createSilenceMp3($part['pause'], $silencePath);
            $allAudioFiles[] = $silencePath;
        }
    }

    $outputPath = "/tmp/final_voice_" . uniqid() . ".mp3";
    return mergeAudios($allAudioFiles, $outputPath) ? $outputPath : null;
}

// 🗣 Generar audio con OpenAI y guardarlo en archivo
function generateOpenAIAudio($text, $filePath) {
    $apiKey = $_ENV["OPENAI_TOKEN"];
    $url = 'https://api.openai.com/v1/audio/speech';

    $data = [
        "model" => "tts-1",
        "voice" => "alloy",
        "input" => $text,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        file_put_contents($filePath, $response);
        return true;
    }

    return false;
}

// 🤫 Crear silencio de N segundos como MP3 usando ffmpeg
function createSilenceMp3($seconds, $filePath) {
    $cmd = "ffmpeg -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 -t {$seconds} -q:a 9 -acodec libmp3lame {$filePath} -y";
    exec($cmd);
}

// 🎧 Unir todos los fragmentos en un solo MP3 final
function mergeAudios($inputFiles, $outputPath) {
    $listFile = "/tmp/concat_list_" . uniqid() . ".txt";
    $handle = fopen($listFile, 'w');
    foreach ($inputFiles as $file) {
        fwrite($handle, "file '$file'\n");
    }
    fclose($handle);

    $cmd = "ffmpeg -f concat -safe 0 -i {$listFile} -c copy {$outputPath} -y";
    exec($cmd);

    return file_exists($outputPath);
}

try {
    $router->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
