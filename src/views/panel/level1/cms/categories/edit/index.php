<?php

use App\Utils\LocationUtils;

LocationUtils::redirectInternal('panel/cms/pages/categories/edit' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
