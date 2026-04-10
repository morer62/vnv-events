<?php

use App\Utils\LocationUtils;

$categoryId = $_GET['id'] ?? null;

if ($categoryId) {
    LocationUtils::redirectInternal("forum?category=" . $categoryId);
} else {
    LocationUtils::redirectInternal("forum");
}





