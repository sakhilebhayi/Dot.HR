<?php

namespace App\Support\Hr;

use Illuminate\Support\Carbon;

/**
 * The business's own clock for rosters and attendance. Shift times are typed as wall-clock
 * times ("07:00"), so clock-ins, "today" and week boundaries must be read on that same wall
 * clock — not the app's UTC — or a South African 08:00 clock-in lands at 06:00, outside its
 * shift. Values come back labelled in the app timezone with the local wall-clock time, which
 * is how the roster columns store them. Instants (submitted, approved, verified) keep now().
 */
final class LocalTime
{
    public static function now(): Carbon
    {
        return Carbon::now(self::zone())->shiftTimezone(config('app.timezone'));
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    public static function zone(): string
    {
        return (string) config('hr.local_timezone', 'Africa/Johannesburg');
    }
}
