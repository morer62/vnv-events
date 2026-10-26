<?php

namespace App\Services;

use RuntimeException;

final class AiJsonGenerator
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** @var null|callable(string,array,array,int):array{status:int,body:string,error:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * Generate a controlled JSON decision. Model selection remains server-side:
     * routine=Luna, conversational=Terra, complex=Sol.
     */
    public function generate(string $system, array $context, array $shape, array $options = []): array
    {
        $key = trim((string)($_ENV['OPENAI_TOKEN'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('OPENAI_TOKEN is not configured.');
        }

        $profile = (string)($options['profile'] ?? 'routine');
        $effort = (string)($options['reasoning_effort'] ?? ($profile === 'complex' ? 'medium' : 'low'));
        $timeout = max(8, min(60, (int)($options['timeout'] ?? 30)));
        $schemaName = preg_replace('/[^a-z0-9_]+/i', '_', (string)($options['schema_name'] ?? 'mochi_result')) ?: 'mochi_result';
        $payload = [
            'model' => $this->modelFor($profile),
            'instructions' => $system.' Return only the requested JSON. Never invent facts, prices, availability, addresses, reviews or promises.',
            'input' => "Required JSON shape and field rules:\n".
                json_encode($shape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\nVerified context:\n".
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reasoning' => ['effort' => $effort],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
                'strict' => false,
                'schema' => $this->schemaFromShape($shape),
            ]],
            'max_output_tokens' => max(256, min(4096, (int)($options['max_output_tokens'] ?? 1600))),
        ];

        $headers = ['Authorization: Bearer '.$key, 'Content-Type: application/json'];
        $last = ['status' => 0, 'body' => '', 'error' => ''];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $last = $this->request(self::ENDPOINT, $payload, $headers, $timeout);
            if ($last['status'] >= 200 && $last['status'] < 300) {
                break;
            }
            if (!in_array($last['status'], [408, 409, 429, 500, 502, 503, 504], true)) {
                break;
            }
            usleep(250000 * ($attempt + 1));
        }

        if ($last['status'] < 200 || $last['status'] >= 300) {
            $decoded = json_decode($last['body'], true);
            $code = trim((string)($decoded['error']['code'] ?? 'HTTP_'.$last['status']));
            $detail = trim((string)($decoded['error']['message'] ?? $last['error']));
            throw new RuntimeException('OpenAI Responses failed ['.$code.']'.($detail !== '' ? ': '.mb_substr($detail, 0, 240) : '.'));
        }

        $response = json_decode($last['body'], true);
        $result = json_decode($this->outputText(is_array($response) ? $response : []), true);
        if (!is_array($result)) {
            throw new RuntimeException('OpenAI Responses returned invalid JSON.');
        }
        return $result;
    }

    public function modelFor(string $profile): string
    {
        return match ($profile) {
            'complex' => trim((string)($_ENV['OPENAI_COMPLEX_MODEL'] ?? 'gpt-6.1-sol')),
            'conversational' => trim((string)($_ENV['OPENAI_CHAT_MODEL'] ?? 'gpt-6.1-terra')),
            default => trim((string)($_ENV['OPENAI_ROUTER_MODEL'] ?? 'gpt-6-luna')),
        };
    }

    private function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }
        foreach ((array)($response['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array)($item['content'] ?? []) as $content) {
                if (in_array((string)($content['type'] ?? ''), ['output_text', 'text'], true)) {
                    return trim((string)($content['text'] ?? ''));
                }
            }
        }
        return '';
    }

    private function schemaFromShape(array $shape): array
    {
        $properties = [];
        foreach ($shape as $key => $example) {
            $properties[(string)$key] = $this->schemaForValue($example);
        }
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    private function schemaForValue(mixed $value): array
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['type' => 'array', 'items' => isset($value[0]) ? $this->schemaForValue($value[0]) : new \stdClass()];
            }
            return $this->schemaFromShape($value);
        }
        $description = is_string($value) ? $value : '';
        if (preg_match('/\b(boolean|true|false)\b/i', $description)) {
            return ['type' => ['boolean', 'null'], 'description' => $description];
        }
        if (preg_match('/\b(integer|numeric|number|price)\b/i', $description)) {
            return ['type' => ['number', 'null'], 'description' => $description];
        }
        return ['type' => ['string', 'null'], 'description' => $description];
    }

    /** @return array{status:int,body:string,error:string} */
    private function request(string $url, array $payload, array $headers, int $timeout): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $payload, $headers, $timeout);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? '' : (string)$body, 'error' => $error];
    }
}
