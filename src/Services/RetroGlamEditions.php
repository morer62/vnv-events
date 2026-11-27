<?php

namespace App\Services;

final class RetroGlamEditions
{
    public const TITLES = [
        'disco-70s' => '’70s Disco in Gold',
        'neon-80s' => '’80s Miami Neon Nights',
        'after-dark-90s' => '’90s R&B & House Party',
        'flashback-00s' => 'Y2K Pop & Hip-Hop Night',
    ];

    public static function requestPath(string $slug, string $intent, bool $success = false): string
    {
        if (!isset(self::TITLES[$slug]) || !in_array($intent, ['host', 'waitlist'], true)) {
            return 'vnv-events-productions/retro-glam#retro-editions';
        }

        $query = ['edition' => $slug, 'intent' => $intent];
        if ($success) {
            $query['submitted'] = 1;
        }
        return 'vnv-events-productions?' . http_build_query($query) . '#priority-list';
    }
}
