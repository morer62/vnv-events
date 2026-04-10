<?php

namespace App\Utils;

class JsonResponse
{


    private mixed $data;
    /**
     * @var int|mixed
     */
    private mixed $status;

    public function __construct($data, $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public static function createResponse(mixed $data, $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    public function handle(): void
    {
        header('Content-Type: application/json');
        http_response_code($this->status);

        echo json_encode($this->data);
    }
}