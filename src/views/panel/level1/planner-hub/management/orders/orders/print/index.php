<?php


use App\Utils\TemplateResponse;

TemplateResponse::renderAndDisplay(__DIR__ . "/index.twig", [
    "id" => $_GET["id"]
]);
