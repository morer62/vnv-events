<?php

namespace App\Services;

use RuntimeException;

final class MochiImageReaderService
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 24 * 1024 * 1024;
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
        $images = [];
        $totalBytes = 0;
        foreach ($files['name'] as $index => $_name) {
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $file[$key] = $files[$key][$index] ?? null;
            }
            $image = $this->validatedImage($file);
            if ($image === null) continue;
            $totalBytes += strlen($image['bytes']);
            if ($totalBytes > self::MAX_TOTAL_BYTES) throw new RuntimeException('Las capturas juntas deben pesar menos de 24 MB.');
            $images[] = $image;
        }
        return $images ? $this->readImages($images) : [];
    }

    public function extract(array $file): string
    {
        $image = $this->validatedImage($file);
        if ($image === null) return '';
        return (string)($this->readImages([$image])[0] ?? '');
    }

    /** @return null|array{mime:string,bytes:string,name:string} */
    private function validatedImage(array $file): ?array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
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
        return ['mime' => $mime, 'bytes' => $bytes, 'name' => basename((string)($file['name'] ?? 'captura'))];
    }

    /** @param array<int,array{mime:string,bytes:string,name:string}> $images */
    private function readImages(array $images): array
    {
        $key = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('La lectura de capturas no está configurada.');
        }
        $content = [[
            'type' => 'input_text',
            'text' => 'Lee estas capturas para el asistente interno de VNV Events. Extrae solamente datos visibles: nombres, email, teléfono, fecha y horario del evento, dirección, servicios, cantidades, precios y notas. Conserva nombres y textos comerciales en inglés tal como aparecen. Marca como [incierto] cualquier dato dudoso y nunca completes datos ausentes. Devuelve un elemento por captura, en el mismo orden.',
        ]];
        foreach ($images as $index => $image) {
            $content[] = ['type' => 'input_text', 'text' => 'Captura '.($index + 1).': '.$image['name']];
            $content[] = ['type' => 'input_image', 'image_url' => 'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']), 'detail' => 'original'];
        }
        $payload = [
            'model' => trim((string)($_ENV['OPENAI_VISION_MODEL'] ?? $_ENV['OPENAI_CHAT_MODEL'] ?? 'gpt-6.1-terra')),
            'reasoning' => ['effort' => 'low'],
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'mochi_screenshot_reading',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => ['screenshots' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => ['index' => ['type' => 'integer'], 'text' => ['type' => 'string']],
                        'required' => ['index', 'text'],
                        'additionalProperties' => false,
                    ]]],
                    'required' => ['screenshots'],
                    'additionalProperties' => false,
                ],
            ]],
            'max_output_tokens' => 2200,
        ];
        $body = '';$status = 0;$error = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $raw = curl_exec($ch);$error = curl_error($ch);$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);curl_close($ch);$body = $raw === false ? '' : (string)$raw;
            if ($status >= 200 && $status < 300) break;
            if (!in_array($status, [408, 409, 429, 500, 502, 503, 504], true)) break;
            usleep(250000 * ($attempt + 1));
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);$code = (string)($decoded['error']['code'] ?? 'HTTP_'.$status);
            error_log('[Mochi vision '.$code.'] '.((string)($decoded['error']['message'] ?? $error)));
            throw new RuntimeException('No pude leer las capturas ahora mismo ('.$code.'). No continué con datos incompletos.');
        }
        $response = json_decode($body, true);$text = '';
        foreach ((array)($response['output'] ?? []) as $item) foreach ((array)($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') {$text = (string)($part['text'] ?? '');break 2;}
        $decoded = json_decode($text, true);$results = [];
        foreach ((array)($decoded['screenshots'] ?? []) as $row) {
            $value = trim((string)($row['text'] ?? ''));
            if ($value !== '') $results[] = mb_substr($value, 0, 3000);
        }
        if (!$results) throw new RuntimeException('No encontré información legible en las capturas.');
        return $results;
    }
}
