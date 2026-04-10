<?php

namespace App\Services\AppleService;

use App\Services\ConfigService;

class AppleSignInService
{

    private string $return_url;

    private string $client_code;

    private string $apple_token_url = "https://appleid.apple.com/auth/token";

    public static $USER_TYPES = [
        2 => "venue",
        3 => "vendor",
        5 => "client"
    ];

    use AppleSignInTrait, AppleIdentityTrait;

    public function __construct($return_url, $client_code) {
        $this->return_url = $return_url;
        $this->client_code = $client_code;
    }


    public static function getAppleSignUpUrl(int $level): string
    {
        $appleQueries = http_build_query([
            "response_type" => "code",
            "response_mode" => "form_post",
            "client_id" => ConfigService::$APPLE_SERVICE_ID,
            "scope" => "name email",
            "state" => "xyz",
        ]);

        $levelType = self::$USER_TYPES[intval($level)];

        $appleUrl = ConfigService::$APPLE_SIGN_IN_URL;
        $redirect = ConfigService::$APPLE_REDIRECT_SIGN_UP_URL . "/" . $levelType;

        return "$appleUrl?" . $appleQueries . "&redirect_uri=$redirect";
    }

    public static function getAppleSignInUrl(): string
    {
        $appleQueries = http_build_query([
            "response_type" => "code",
            "response_mode" => "form_post",
            "client_id" => ConfigService::$APPLE_SERVICE_ID,
            "scope" => "name email",
            "state" => "xyz",
        ]);

        $appleUrl = ConfigService::$APPLE_SIGN_IN_URL;
        $redirect = ConfigService::$APPLE_REDIRECT_SIGN_IN_URL;

        return "$appleUrl?" . $appleQueries . "&redirect_uri=$redirect";
    }
}