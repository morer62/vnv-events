<?php

namespace App\Services;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Exception;

class TimeService
{

    /**
     * @throws Exception
     */
    public static function getDateDiff(string $date1, string $date2): DateInterval|bool
    {

        $startDate = new DateTime($date1);
        $endTime = new DateTime($date2);

        return $startDate->diff($endTime);
    }

    /**
     * @throws Exception
     */
    public static function getDateLocalDiff(string $date1, string $date2): string
    {
        $interval = self::getDateDiff($date1, $date2);
        return self::getDateLocalDiffFromInterval($interval);
    }

    public static function getDateLocalDiffFromInterval(DateInterval $interval): string
    {
        $hours = $interval->h + ($interval->days * 24); // Include days in the past hours
        $minutes = $interval->i; // Only remaining minutes

        return "$hours hours and $minutes minutes.";
    }

    /**
     * @throws Exception
     */
    public static function sumAllIntervals(array $intervals): DateInterval|bool
    {
        $totalSeconds = 0;

        foreach ($intervals as $interval) {
            $start = new DateTimeImmutable('@0'); // epoch
            $end = $start->add($interval);
            $totalSeconds += $end->getTimestamp();
        }

        // Convert total seconds to a DateInterval
        return (new DateTimeImmutable('@0'))
            ->add(new DateInterval('PT' . $totalSeconds . 'S'))
            ->diff(new DateTimeImmutable('@0'));
    }
}