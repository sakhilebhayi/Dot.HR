<?php

namespace App\Services\Hr;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Counts working days for leave. A day counts unless its ISO weekday (1 = Monday ...
 * 7 = Sunday) is in `weekend_days` or its date is in `public_holidays` (both in
 * config/hr.php). A half day applies only to a single-day request and is worth 0.5 on a
 * working day. Changing the config changes how past requests are counted too: nothing
 * about the count is stored on the request.
 */
class LeaveCalendar
{
    /**
     * @param  list<int>  $weekendDays
     * @param  list<string>  $publicHolidays  Y-m-d
     */
    /** @var array<string, int> */
    private array $holidays;

    public function __construct(private array $weekendDays, array $publicHolidays)
    {
        $this->holidays = array_flip($publicHolidays);
    }

    /**
     * @param  array{weekend_days?: list<int>, public_holidays?: list<string>}|null  $config
     */
    public static function fromConfig(?array $config = null): self
    {
        $config ??= config('hr');

        return new self($config['weekend_days'] ?? [6, 7], $config['public_holidays'] ?? []);
    }

    public function isWorkingDay(CarbonInterface|string $date): bool
    {
        $day = CarbonImmutable::parse($date);

        return ! in_array($day->dayOfWeekIso, $this->weekendDays, true)
            && ! isset($this->holidays[$day->toDateString()]);
    }

    public function workingDays(CarbonInterface|string $start, CarbonInterface|string $end, ?string $halfDay = null): float
    {
        $start = CarbonImmutable::parse($start)->startOfDay();
        $end = CarbonImmutable::parse($end)->startOfDay();

        if ($halfDay !== null && $start->equalTo($end)) {
            return $this->isWorkingDay($start) ? 0.5 : 0.0;
        }

        return $this->count($start, $end);
    }

    /**
     * Working days of [start, end] that fall inside [clipStart, clipEnd] (for splitting a
     * request across leave years). A half day is kept as a half when its day is inside.
     */
    public function workingDaysWithin(
        CarbonInterface|string $start,
        CarbonInterface|string $end,
        CarbonInterface|string $clipStart,
        CarbonInterface|string $clipEnd,
        ?string $halfDay = null,
    ): float {
        $start = CarbonImmutable::parse($start)->startOfDay();
        $end = CarbonImmutable::parse($end)->startOfDay();
        $from = $start->max(CarbonImmutable::parse($clipStart)->startOfDay());
        $to = $end->min(CarbonImmutable::parse($clipEnd)->startOfDay());

        if ($from->greaterThan($to)) {
            return 0.0;
        }

        if ($halfDay !== null && $start->equalTo($end)) {
            return $this->isWorkingDay($start) ? 0.5 : 0.0;
        }

        return $this->count($from, $to);
    }

    private function count(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $days = 0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($this->isWorkingDay($day)) {
                $days++;
            }
        }

        return (float) $days;
    }
}
