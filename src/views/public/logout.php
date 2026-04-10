<?php


use App\Services\LoginService;
use App\Utils\LocationUtils;

LoginService::logout();
LocationUtils::redirectInternal('login');
