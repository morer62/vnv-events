<?php

namespace App\Services;

use App\Utils\LocationUtils;
use InvalidArgumentException;

final class OrderProposalAccessService
{
    private const SCOPE = 'order_proposal';

    public function createToken(object $order): string
    {
        $orderId = (int)($order->id ?? 0);
        $clientId = (int)($order->id_client ?? 0);
        if ($orderId <= 0 || $clientId <= 0) {
            throw new InvalidArgumentException('The order cannot be used to create a proposal link.');
        }

        $minimumExpiry = strtotime('today +180 days 23:59:59');
        $eventExpiry = !empty($order->event_date)
            ? strtotime((string)$order->event_date . ' +90 days 23:59:59')
            : false;

        $payload = [
            'order_id' => $orderId,
            'user_id' => $clientId,
            'scope' => self::SCOPE,
            'exp' => max((int)$minimumExpiry, (int)$eventExpiry),
        ];
        $payload['hash'] = $this->sign($payload);

        return $this->base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /** @return array{order_id:int,user_id:int,scope:string,exp:int,hash:string} */
    public function validateToken(string $token): array
    {
        $json = $this->base64UrlDecode($token);
        $payload = $json !== null ? json_decode($json, true) : null;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Invalid proposal link.');
        }

        foreach (['order_id', 'user_id', 'scope', 'exp', 'hash'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException('Invalid proposal link.');
            }
        }

        $signedPayload = [
            'order_id' => (int)$payload['order_id'],
            'user_id' => (int)$payload['user_id'],
            'scope' => (string)$payload['scope'],
            'exp' => (int)$payload['exp'],
        ];
        if ($signedPayload['order_id'] <= 0
            || $signedPayload['user_id'] <= 0
            || $signedPayload['scope'] !== self::SCOPE
            || $signedPayload['exp'] < time()
            || !hash_equals($this->sign($signedPayload), (string)$payload['hash'])) {
            throw new InvalidArgumentException('Invalid or expired proposal link.');
        }

        return [...$signedPayload, 'hash' => (string)$payload['hash']];
    }

    public function urlForOrder(object $order): string
    {
        return LocationUtils::pathFor('proposal-access?token=' . rawurlencode($this->createToken($order)));
    }

    /** @param array{order_id:int,user_id:int,scope:string,exp:int} $payload */
    private function sign(array $payload): string
    {
        $secret = (string)($_ENV['VNV_SECRET_KEY'] ?? 'mySuperSecretKey');
        return hash_hmac('sha256', (string)json_encode($payload, JSON_UNESCAPED_SLASHES), $secret);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
