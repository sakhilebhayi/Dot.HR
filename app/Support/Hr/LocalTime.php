<?php

namespace App\Support\Hr;

use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The business's own clock for rosters and attendance. Shift times are typed as wall-clock
 * times ("07:00"), so clock-ins, "today" and week boundaries must be read on that same wall
 * clock — not the app's UTC — or a South African 08:00 clock-in lands at 06:00, outside its
 * shift. Values come back labelled in the app timezone with the local wall-clock time, which
 * is how the roster columns store them. Instants (submitted, approved, verified) keep now().
 *
 * Whose clock: the business's own time zone (teams.timezone, set on the Schedule page) when it
 * has one, else HR_LOCAL_TIMEZONE. In a web request that is the signed-in person's current
 * business; work that runs for a business with nobody signed in wraps itself in forTeam().
 */
final class LocalTime
{
    private static ?string $override = null;

    /** @var array<string, bool> zone => is a real IANA zone, checked once per process */
    private static array $known = [];

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
        if (self::$override !== null) {
            return self::$override;
        }

        $team = Auth::user()?->currentTeam;

        return self::zoneOf($team instanceof Team ? $team : null);
    }

    /** The time zone a business works in: its own, or the app-wide default. */
    public static function zoneOf(?Team $team): string
    {
        $zone = $team?->timezone;

        return is_string($zone) && $zone !== '' && self::isZone($zone) ? $zone : self::defaultZone();
    }

    /** A real IANA time zone name, such as Africa/Nairobi. */
    public static function isZone(string $zone): bool
    {
        return self::$known[$zone] ??= in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    public static function defaultZone(): string
    {
        return (string) config('hr.local_timezone', 'Africa/Johannesburg');
    }

    /**
     * Run $callback on this business's clock, whoever is signed in (scheduled commands, queued
     * work). Not sticky: the previous clock comes back in a finally block.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forTeam(Team $team, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = self::zoneOf($team);

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
