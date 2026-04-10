<?php

namespace App\Utils;

class FormatPhone
{
    public static function formatPhone($phone): array|string|null
    {
        return preg_replace("/[^0-9|+]/", "", $phone);
    }
}