<?php

namespace App\Services;

use RuntimeException;

final class MochiAudioTranscriptionService
{
    private const MAX_BYTES = 12_000_000;

    public function transcribe(array $upload): string
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No pude recibir el audio. Intenta dictarlo otra vez.');
        }

        $path = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($path === '' || !is_uploaded_file($path) || $size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('El audio está vacío o supera el límite de 12 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $allowed = ['audio/webm', 'video/webm', 'audio/mp4', 'video/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/aac'];
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Este formato de audio no es compatible.');
        }

        $key = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('El servicio de dictado no está configurado.');
        }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($upload['name'] ?? 'mochi-audio.webm')) ?: 'mochi-audio.webm';
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key],
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($path, $mime, $name),
                'model' => (string)($_ENV['OPENAI_TRANSCRIPTION_MODEL'] ?? 'whisper-1'),
                'language' => 'es',
                'response_format' => 'json',
                'prompt' => 'VNV Events. Transcribe Spanish and English event details, customer names, emails, addresses, dates, times, quantities and service names accurately.',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new RuntimeException('No pude transcribir el audio. '.($curlError ?: 'Intenta nuevamente.'));
        }
        $json = json_decode($response, true);
        $text = trim((string)($json['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('No detecté palabras en el audio. Acércate al micrófono e intenta otra vez.');
        }
        return $text;
    }
}
