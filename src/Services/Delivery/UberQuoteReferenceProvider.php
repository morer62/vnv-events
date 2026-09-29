<?php

namespace App\Services\Delivery;

final class UberQuoteReferenceProvider implements QuoteReferenceProvider
{
    private const TOKEN_URL = 'https://auth.uber.com/oauth/v2/token';
    private const API_BASE = 'https://api.uber.com/v1/customers/';

    private static ?array $tokenCache = null;

    public function getCode(): string { return 'uber_reference'; }

    public function isConfigured(): bool
    {
        return $this->env('UBER_CUSTOMER_ID') !== '' && $this->env('UBER_CLIENT_ID') !== '' && $this->env('UBER_SECRET') !== '';
    }

    public function getQuote(array $pickup, array $destination, array $context = []): array
    {
        if (!$this->isConfigured()) return $this->failure('Uber reference credentials are not configured.');
        try {
            $token = $this->accessToken();
            $payload = [
                'pickup_address' => json_encode($this->structuredAddress($pickup), JSON_UNESCAPED_SLASHES),
                'dropoff_address' => json_encode($this->structuredAddress($destination), JSON_UNESCAPED_SLASHES),
            ];
            if (!empty($context['pickup_ready_dt'])) $payload['pickup_ready_dt'] = (string)$context['pickup_ready_dt'];
            if (!empty($context['pickup_deadline_dt'])) $payload['pickup_deadline_dt'] = (string)$context['pickup_deadline_dt'];
            if (!empty($context['dropoff_ready_dt'])) $payload['dropoff_ready_dt'] = (string)$context['dropoff_ready_dt'];
            if (!empty($context['dropoff_deadline_dt'])) $payload['dropoff_deadline_dt'] = (string)$context['dropoff_deadline_dt'];
            $response = $this->request(
                self::API_BASE . rawurlencode($this->env('UBER_CUSTOMER_ID')) . '/delivery_quotes',
                $payload,
                ['Authorization: Bearer ' . $token]
            );
            $fee = isset($response['fee']) ? ((float)$response['fee'] / 100) : 0.0;
            if (($response['kind'] ?? '') !== 'delivery_quote' || $fee <= 0) throw new \RuntimeException('Uber did not return a usable quote.');
            return [
                'success' => true, 'provider' => $this->getCode(),
                'provider_quote_id' => (string)($response['id'] ?? ''),
                'provider_cost' => round($fee, 2),
                'currency' => strtoupper((string)($response['currency'] ?? 'USD')),
                'eta_minutes' => isset($response['duration']) ? (int)$response['duration'] : null,
                'expires_at' => $this->sqlDate($response['expires'] ?? null),
                'raw_response' => json_encode($response, JSON_UNESCAPED_SLASHES), 'error' => null,
            ];
        } catch (\Throwable $e) {
            error_log('[Uber Quote Reference] ' . $e->getMessage());
            return $this->failure($e->getMessage());
        }
    }

    private function accessToken(): string
    {
        if (self::$tokenCache && (int)self::$tokenCache['expires_at'] > time() + 60) return self::$tokenCache['token'];
        $response = $this->request(self::TOKEN_URL, [
            'client_id' => $this->env('UBER_CLIENT_ID'), 'client_secret' => $this->env('UBER_SECRET'),
            'grant_type' => 'client_credentials', 'scope' => 'eats.deliveries',
        ], [], false);
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '' || ($response['scope'] ?? '') !== 'eats.deliveries') throw new \RuntimeException('Uber OAuth authentication failed.');
        self::$tokenCache = ['token' => $token, 'expires_at' => time() + max(300, (int)($response['expires_in'] ?? 3600))];
        return $token;
    }

    private function request(string $url, array $payload, array $headers = [], bool $json = true): array
    {
        $ch = curl_init($url);
        $body = $json ? json_encode($payload, JSON_UNESCAPED_SLASHES) : http_build_query($payload);
        $headers[] = $json ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12]);
        $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($error !== '' || $status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string)($decoded['message'] ?? $decoded['code'] ?? 'Uber request failed.') : ($error ?: 'Uber request failed.');
            throw new \RuntimeException($message);
        }
        return $decoded;
    }

    private function structuredAddress(array $address): array
    {
        return ['street_address'=>array_values(array_filter([(string)($address['address_1']??$address['street_address']??''),(string)($address['address_2']??'')])),
            'city'=>(string)($address['city']??''),'state'=>(string)($address['state']??''),'zip_code'=>(string)($address['zip']??$address['zip_code']??''),'country'=>(string)($address['country']??'US')];
    }

    private function env(string $key): string { return trim((string)($_ENV[$key] ?? getenv($key) ?: '')); }
    private function sqlDate(mixed $value): ?string { try { return $value ? (new \DateTimeImmutable((string)$value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null; } catch (\Throwable) { return null; } }
    private function failure(string $error): array { return ['success'=>false,'provider'=>$this->getCode(),'provider_quote_id'=>null,'provider_cost'=>0.0,'currency'=>'USD','eta_minutes'=>null,'expires_at'=>null,'raw_response'=>null,'error'=>$error]; }
}
