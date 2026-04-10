<?php

use App\Utils\TemplateResponse;

TemplateResponse::renderAndDisplay(__DIR__ . "/index.twig", [
    "id" => $_GET["id"],
    "suborder_id" => $_GET["suborder"] ?? null
]);
