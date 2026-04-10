<?php

namespace App\Utils;

class Request
{
    public int $receivedAt;

    public function __construct()
    {
        $this->receivedAt = time();
    }

    public function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    public function getUri(): string
    {
        return $_SERVER['REQUEST_URI'];
    }

    public function getBody(): array
    {
        return json_decode(file_get_contents('php://input'), true);
    }

    public function getHeaders(): array
    {
        return getallheaders();
    }

    public function getQueryParams(): array
    {
        return $_GET;
    }
}