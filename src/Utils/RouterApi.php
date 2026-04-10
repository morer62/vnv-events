<?php

namespace App\Utils;

use Closure;
use Exception;

class RouterApi extends Router
{

    protected Closure $putCallback;
    protected Closure $deleteCallback;
    protected Closure $patchCallback;

    public function put(Closure $callback): RouterApi
    {
        $this->putCallback = $callback;
        return $this;
    }

    public function delete(Closure $callback): RouterApi
    {
        $this->deleteCallback = $callback;
        return $this;
    }

    public function patch(Closure $callback): RouterApi
    {
        $this->patchCallback = $callback;
        return $this;
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $result = null;
        $callable = null;

        if ($method == "GET" && isset($this->getCallback)) {
            $callable = $this->getCallback;
        }

        if ($method == "POST" && isset($this->postCallback)) {
            $callable = $this->postCallback;
        }

        if ($method == "PUT" && isset($this->putCallback)) {
            $callable = $this->putCallback;
        }

        if ($method == "DELETE" && isset($this->deleteCallback)) {
            $callable = $this->deleteCallback;
        }

        if ($callable != null) {
            $result = $callable(new Request());
        }

        if ($result == null) {
            JsonResponse::createResponse(["message" => "Method not allowed"], 405)->handle();
            return;
        }

        if ($result instanceof Response || $result instanceof JsonResponse) {
            $result->handle();
        } else {
            echo $result;
        }
    }
}