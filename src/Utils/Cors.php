<?php

namespace App\Utils;

class Cors
{
    public static function handle(): void
    {
        $allowedOriginsPattern = $_ENV["CORS_ALLOWED_ORIGINS"];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Check if the origin matches the allowed pattern
        if (preg_match($allowedOriginsPattern, $origin)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            header("Access-Control-Allow-Origin: 'null'"); // Reject if not matched
        }

        // Set other necessary CORS headers
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");

        // Handle preflight requests (OPTIONS method)
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}