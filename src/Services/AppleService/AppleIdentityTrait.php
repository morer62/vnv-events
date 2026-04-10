<?php

namespace App\Services\AppleService;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

trait AppleIdentityTrait
{

    /**
     * @throws Exception
     */
    function verifyAppleIdentityToken($identityToken): stdClass
    {
        // 1. Fetch Apple’s public keys
        $keys = json_decode(file_get_contents('https://appleid.apple.com/auth/keys'), true);

        // 2. Decode token header (to find matching key)
        $tokenParts = explode('.', $identityToken);
        $header = json_decode(base64_decode($tokenParts[0]), true);
        $kid = $header['kid'] ?? null;
        $alg = $header['alg'] ?? 'RS256';

        $publicKeyData = null;
        foreach ($keys['keys'] as $key) {
            if ($key['kid'] === $kid) {
                $publicKeyData = $key;
                break;
            }
        }

        if (!$publicKeyData) {
            throw new Exception("No matching Apple public key found.");
        }

        // 3. Build the public key
        $pem = $this->buildPemKey($publicKeyData);

        // 4. Verify and decode token
        $decoded = JWT::decode($identityToken, new Key($pem, $alg));

        // 5. Validate claims
        if ($decoded->iss !== 'https://appleid.apple.com') {
            throw new Exception("Invalid issuer.");
        }

        if ($decoded->exp < time()) {
            throw new Exception("Token expired.");
        }

        return $decoded; // contains sub (unique user ID), email, etc.
    }

    function buildPemKey($keyData): string
    {
        $modulus = $this->base64UrlDecode($keyData['n']);
        $exponent = $this->base64UrlDecode($keyData['e']);

        $components = [
            'modulus' => $modulus,
            'publicExponent' => $exponent
        ];

        $modulus = pack('Ca*a*', 0x02, $this->encodeLength(strlen($components['modulus'])), $components['modulus']);
        $publicExponent = pack('Ca*a*', 0x02, $this->encodeLength(strlen($components['publicExponent'])), $components['publicExponent']);
        $rsaPublicKey = pack('Ca*a*a*', 0x30, $this->encodeLength(strlen($modulus . $publicExponent)), $modulus, $publicExponent);
        $rsaOID = pack('H*', '300d06092a864886f70d0101010500');
        $rsaPublicKey = pack('Ca*a*', 0x03, $this->encodeLength(strlen($rsaPublicKey) + 1), "\0" . $rsaPublicKey);
        $rsaPublicKey = pack('Ca*a*a*', 0x30, $this->encodeLength(strlen($rsaOID . $rsaPublicKey)), $rsaOID, $rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($rsaPublicKey), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
    }

    function base64UrlDecode($data): bool|string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    function encodeLength($length): string
    {
        if ($length <= 0x7F) {
            return chr($length);
        }
        $temp = ltrim(pack('N', $length), chr(0));
        return pack('Ca*', 0x80 | strlen($temp), $temp);
    }
}