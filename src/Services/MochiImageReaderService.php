<?php

namespace App\Services;

use RuntimeException;

final class MochiImageReaderService
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function extractMany(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            $text = $this->extract($files);
            return $text === '' ? [] : [$text];
        }
        if (count($files['name']) > 5) {
            throw new RuntimeException('Puedes adjuntar hasta 5 capturas por mensaje.');
        }
        $results = [];
        foreach ($files['name'] as $index => $_name) {
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $file[$key] = $files[$key][$index] ?? null;
            }
            $text = $this->extract($file);
            if ($text !== '') {
                $results[] = $text;
            }
        }
        return $results;
    }

    public function extract(array $file): string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No pude recibir la captura. Intenta pegarla o seleccionarla nuevamente.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || $size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('La captura debe pesar menos de 8 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Usa una captura JPG, PNG o WebP.');
        }
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new RuntimeException('No pude leer la captura.');
        }
        $key = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('La lectura de capturas no esta configurada.');
        }
        $payload = [
            'model' => trim((string)($_ENV['OPENAI_VISION_MODEL'] ?? $_ENV['OPENAI_TEXT_MODEL'] ?? 'gpt-4o-mini')),
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Read this screenshot for the internal VNV Events assistant. Transcribe relevant text accurately and organize names, email, phone, event date/time, address, services, quantities, prices and notes when visible. Explicitly label uncertain or unreadable text and never guess missing values. Explain the extracted information in Spanish for the internal operator, but preserve customer names, service names and customer-facing wording in English exactly as shown. Return JSON only as {"text":"concise structured transcription"}.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'detail' => 'high']],
                ],
            ]],
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('No pude leer la captura ahora mismo.');
        }
        $response = json_decode((string)$body, true);
        $decoded = json_decode((string)($response['choices'][0]['message']['content'] ?? ''), true);
        $text = trim((string)($decoded['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('No encontre texto legible en la captura.');
        }
        return mb_substr($text, 0, 2200);
    }
}
