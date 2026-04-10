<?php

use App\Services\LoginService;
use App\Repositories\CrmCategoryRepository;
use App\Utils\TemplateResponse;

$user = LoginService::getSession();
$repo = new CrmCategoryRepository();

TemplateResponse::renderAndDisplay(__DIR__ . "/index.twig");
