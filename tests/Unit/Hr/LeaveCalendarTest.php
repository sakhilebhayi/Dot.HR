<?php

namespace Tests\Unit\Hr;

use App\Services\Hr\LeaveCalendar;
use PHPUnit\Framework\TestCase;

class LeaveCalendarTest extends TestCase
{
    public function test_a_monday_to_friday_week_is_five_working_days(): void
    {
        $this->assertSame(5.0, (new LeaveCalendar([6, 7], []))->workingDays('2026-03-02', '2026-03-06'));
    }

    public function test_weekends_inside_the_range_are_skipped(): void
    {
        $this->assertSame(6.0, (new LeaveCalendar([6, 7], []))->workingDays('2026-03-02', '2026-03-09'));
    }

    public function test_a_range_that_is_only_a_weekend_is_zero(): void
    {
        $this->assertSame(0.0, (new LeaveCalendar([6, 7], []))->workingDays('2026-03-07', '2026-03-08'));
    }

    public function test_weekend_days_are_configurable(): void
    {
        // Friday and Saturday off: Mon, Tue, Wed, Thu and the Sunday are working days.
        $this->assertSame(5.0, (new LeaveCalendar([5, 6], []))->workingDays('2026-03-02', '2026-03-08'));
    }

    public function test_configured_public_holidays_are_not_counted(): void
    {
        $this->assertSame(4.0, (new LeaveCalendar([6, 7], ['2026-03-04']))->workingDays('2026-03-02', '2026-03-06'));
    }

    public function test_a_half_day_on_a_single_working_day_is_half(): void
    {
        $this->assertSame(0.5, (new LeaveCalendar([6, 7], []))->workingDays('2026-03-02', '2026-03-02', 'am'));
    }

    public function test_a_half_day_on_a_non_working_day_is_zero(): void
    {
        $this->assertSame(0.0, (new LeaveCalendar([6, 7], []))->workingDays('2026-03-07', '2026-03-07', 'pm'));
    }

    public function test_a_half_day_is_ignored_on_a_multi_day_range(): void
    {
        $this->assertSame(2.0, (new LeaveCalendar([6, 7], []))->workingDays('2026-03-02', '2026-03-03', 'am'));
    }

    public function test_counting_can_be_clipped_to_a_window(): void
    {
        $calendar = new LeaveCalendar([6, 7], []);

        // 2026-12-28 (Mon) to 2027-01-08 (Fri), clipped to 2026: Mon-Thu... 28,29,30,31 = 4.
        $this->assertSame(4.0, $calendar->workingDaysWithin('2026-12-28', '2027-01-08', '2026-01-01', '2026-12-31'));
        $this->assertSame(6.0, $calendar->workingDaysWithin('2026-12-28', '2027-01-08', '2027-01-01', '2027-12-31'));
    }

    public function test_it_reads_weekend_and_holiday_config_by_default(): void
    {
        $calendar = LeaveCalendar::fromConfig(['weekend_days' => [7], 'public_holidays' => ['2026-03-03']]);

        $this->assertSame(5.0, $calendar->workingDays('2026-03-02', '2026-03-07'), 'Mon-Sat is six days, minus the holiday');
    }
}
