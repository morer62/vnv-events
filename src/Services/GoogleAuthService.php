<?php

namespace App\Services;

use Exception;
use Google_Client;

class GoogleAuthService
{

    function verifyGoogleIdToken($idToken): ?array
    {

        $client = new Google_Client([
            'client_id' => ConfigService::$GOOGLE_CLIENT_ID
        ]);

        try {
            // Verify the token
            $payload = $client->verifyIdToken($idToken);

            if ($payload) {
                // Token is valid
                return [
                    'user_id' => $payload['sub'],
                    'email' => $payload['email'],
                    'name' => $payload['name'] ?? null,
                    'picture' => $payload['picture'] ?? null,
                ];
            } else {
                // Invalid token
                return null;
            }
        } catch (Exception $e) {
            echo 'Google ID token verification error: ' . $e->getMessage();
            return null;
        }
    }
}