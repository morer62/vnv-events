<?php

namespace App\Services\AppleService;

use App\Entity\UserAppleJwt;
use App\Services\ConfigService;
use App\Utils\HttpUtil;
use App\Utils\LocationUtils;
use Exception;
use Firebase\JWT\JWT;

trait AppleSignInTrait
{
    /**
     * @throws Exception
     */
    public function handleSignUp(): UserAppleJwt
    {
        $client_secret = $this->generateClientSecret();
        [$data, $response] = $this->authenticateAppleCode($client_secret);

        if (!isset($data['id_token'])) {
            throw new Exception("Error en la respuesta de Apple: ". $response);
        }

        return $this->JwtToUserAppleJwt($data);
    }

    private function generateClientSecret(): string
    {

        $private_key = file_get_contents(
            LocationUtils::getRootLocation() . "/.secrets/" . ConfigService::$APPLE_SECRET_NAME
        );

        $header = ['alg' => 'ES256', 'kid' => ConfigService::$APPLE_KEY_ID];

        $claims = [
            'iss' => ConfigService::$APPLE_TEAM_ID,
            'iat' => time(),
            'exp' => time() + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => ConfigService::$APPLE_SERVICE_ID,
        ];

        return JWT::encode($claims, $private_key, 'ES256', ConfigService::$APPLE_KEY_ID, $header);
    }

    private function authenticateAppleCode($client_secret): array
    {
        $post_fields = [
            'grant_type' => 'authorization_code',
            'code' => $this->client_code,
            'redirect_uri' => $this->return_url,
            'client_id' => ConfigService::$APPLE_SERVICE_ID,
            'client_secret' => $client_secret,
        ];

        $response = HttpUtil::post($this->apple_token_url, $post_fields);
        return [json_decode($response, true), $response];
    }

    private function JwtToUserAppleJwt($data): UserAppleJwt
    {
        $id_token = $data['id_token'];
        $parts = explode(".", $id_token);
        $payload = json_decode(base64_decode($parts[1]), true);
        return new UserAppleJwt($payload);
    }
}
