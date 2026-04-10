<?php

namespace App\Services;

use Exception;

class OpenAIService
{

    /**
     * @throws Exception
     */
    public static function generateOpenAIAudio($text): ?string
    {
        $apiKey = $_ENV["OPENAI_TOKEN"];

        $ch = curl_init('https://api.openai.com/v1/audio/speech');

        $data = [
            "model" => "tts-1",
            "voice" => "alloy",
            "input" => $text,
        ];

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Get response as a string
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg', // Important for audio output
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception("Cannot generate");
        }

        return $response;
    }
}