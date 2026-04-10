<?php

namespace App\Services;

use Twilio\Exceptions\ConfigurationException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioService
{

    private Client $client;

    /**
     * @throws ConfigurationException
     */
    public function __construct() {

    }

    /**
     * @throws ConfigurationException
     */
    private function createClient(): void
    {
        $this->client = new Client($_ENV["TWILIO_ID"] ?? "", $_ENV["TWILIO_TOKEN"] ?? "");
    }

    /**
     * @throws TwilioException
     * @throws ConfigurationException
     */
    public function sendMessage(string $to, string $message) : bool {
        if ($_ENV["APP_ENV"] == "debug") {
            return true;
        }

        $this->createClient();
        $this->client->messages->create($to, [
           "from" => $_ENV["TWILIO_NUMBER"],
            "body" => $message
        ]);

        return true;
    }
}