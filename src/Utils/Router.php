<?php

namespace App\Utils;

use Closure;
use Exception;


class Router
{

    protected Closure $postCallback;
    protected Closure $getCallback;


    public function __construct(){}

    /**
     * @param Closure():string $callback must return a Response or a string or never
     * @return Router
     */
    public function post(Closure $callback): Router
    {
        $this->postCallback = $callback;
        return $this;
    }

    /**
     * @param Closure():string $callback must return a Response or a string or never
     * @return Router
     */
    public function get(Closure $callback): Router
    {
        $this->getCallback = $callback;
        return $this;
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $result = null;

        if ($method == "GET" && isset($this->getCallback)) {
            $callable = $this->getCallback;
            $result = $callable();
        }

        if ($method == "POST" && isset($this->postCallback)) {
            $callable = $this->postCallback;
            $result = $callable();
        }

        if ($result == null) {
            return;
        }

        if ($result instanceof Response || $result instanceof JsonResponse) {
            $result->handle();
        } else {
            echo $result;
        }


    }

}