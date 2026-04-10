<?php

namespace App\Utils;

use DateTime;
use DateTimeZone;
use Exception;

class DateUtil
{
    /**
     * @throws Exception
     */
    public static function convertToUtcTime($datetime, $timezone = 'UTC'): string
    {

        $date = new DateTime($datetime, new DateTimeZone($timezone));

        // Convert to UTC
        $date->setTimezone(new DateTimeZone('UTC'));

        // Output in UTC
        return $date->format('Y-m-d H:i:s');
    }
}