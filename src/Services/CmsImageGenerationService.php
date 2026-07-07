<?php

namespace App\Services;

use App\Utils\FileUtils;
use Exception;

class CmsImageGenerationService
{
    public function generateAndUpload(string $prompt, string $folder = 'cms/generated-images', string $size = '1024x1024'): array
    {
        @ini_set('max_execution_time', '900');
        @ini_set('default_socket_timeout', '900');
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new Exception('Image prompt is required.');
        }

        $prompt = $this->toHyperrealisticPrompt($prompt);

        $apiKey = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('OPENAI_TOKEN is not configured.');
        }

        $model = trim((string)($_ENV['OPENAI_IMAGE_MODEL'] ?? 'gpt-image-1'));
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'n' => 1,
        ];

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 180,
        ]);

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new Exception('OpenAI image generation failed: ' . ($error ?: $response));
        }

        $json = json_decode((string)$response, true);
        $item = $json['data'][0] ?? null;
        if (!is_array($item)) {
            throw new Exception('OpenAI image generation returned no image data.');
        }

        $binary = '';
        if (!empty($item['b64_json'])) {
            $binary = (string)base64_decode((string)$item['b64_json'], true);
        } elseif (!empty($item['url'])) {
            $binary = $this->fetchImageBinary((string)$item['url']);
        }

        if ($binary === '') {
            throw new Exception('Generated image payload was empty.');
        }

        $url = FileUtils::saveFileFromContent($binary, $folder, 'png');

        return [
            'url' => $url,
            'prompt' => $prompt,
            'model' => $model,
            'size' => $size,
        ];
    }

    public function generateAndUploadWithRetry(string $prompt, string $folder = 'cms/generated-images', string $size = '1024x1024', int $attempts = 2): array
    {
        $attempts = max(1, $attempts);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->generateAndUpload($prompt, $folder, $size);
            } catch (Exception $error) {
                $lastError = $error;
                if ($attempt < $attempts) {
                    sleep(2);
                }
            }
        }

        throw $lastError ?: new Exception('Image generation failed.');
    }

    private function toHyperrealisticPrompt(string $prompt): string
    {
        return trim($prompt) . ' Hyperrealistic professional event photography, natural lighting, realistic people and environments, premium editorial photo quality, true-to-life colors, no illustration, no cartoon, no anime, no 3D render, no text overlay, no logos, no watermark.';
    }

    public function generateMany(array $prompts, int $limit, string $folder = 'cms/generated-images', string $size = '1024x1024'): array
    {
        $images = [];
        foreach (array_slice(array_values(array_filter($prompts, static fn ($prompt): bool => trim((string)$prompt) !== '')), 0, max(0, $limit)) as $prompt) {
            $images[] = $this->generateAndUpload((string)$prompt, $folder, $size);
        }

        return $images;
    }

    private function fetchImageBinary(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $binary = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($binary) || $binary === '' || $status < 200 || $status >= 300) {
            throw new Exception('Could not download generated image.');
        }

        return $binary;
    }
}
