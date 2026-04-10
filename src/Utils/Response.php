<?php

namespace App\Utils;

class Response
{

    private string $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public static function createResponse(string $data): Response
    {
        return new Response($data);
    }

    public function handle(): void {
        echo $this->data;
    }
}