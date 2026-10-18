<?php

$days = static fn (string $value): array => array_values(array_filter(
    array_map('intval', explode(',', $value)),
    static fn (int $d): bool => $d >= 1 && $d <= 7,
));

$dates = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Employees and leave requests are soft-deleted. `php artisan hr:purge-expired`
    | (scheduled daily) permanently removes rows that were soft-deleted more than
    | this many days ago. Default: 3 years (1095 days), a common employment-record
    | horizon. It is a starting default, not legal advice: set HR_RETENTION_DAYS to
    | what your own obligations require. Audit-log rows are never purged by it.
    */
    'retention_days' => (int) env('HR_RETENTION_DAYS', 365 * 3),

    /*
    |--------------------------------------------------------------------------
    | Working days
    |--------------------------------------------------------------------------
    | ISO-8601 weekday numbers (1 = Monday ... 7 = Sunday) that never count as
    | leave days, e.g. HR_WEEKEND_DAYS=5,6 for a Friday-Saturday weekend.
    | `public_holidays` is a list of Y-m-d dates that do not count either
    | (HR_PUBLIC_HOLIDAYS=2026-12-25,2026-12-26). Nothing is bundled: a holiday
    | calendar is a decision for the deploying company.
    */
    'weekend_days' => $days(env('HR_WEEKEND_DAYS', '6,7')),
    'public_holidays' => $dates(env('HR_PUBLIC_HOLIDAYS', '')),

    /*
    |--------------------------------------------------------------------------
    | Leave year
    |--------------------------------------------------------------------------
    | The month (1-12) in which a leave year starts. Balances are entitlement
    | minus approved working days inside the leave year.
    */
    'leave_year_start_month' => max(1, min(12, (int) env('HR_LEAVE_YEAR_START_MONTH', 1))),

    /*
    |--------------------------------------------------------------------------
    | Default leave types
    |--------------------------------------------------------------------------
    | Created for every team (and editable by its HR admins at /leave-types).
    | annual_days = null means the type is not tracked against a balance.
    | enforce_balance = true blocks requests that would exceed the balance.
    | carry_over_days is the most that can roll into the next leave year, and
    | never more than what was actually left unused. These numbers are neutral
    | starting points, not a statement of what any law requires.
    */
    'default_leave_types' => [
        ['slug' => 'annual', 'name' => 'Annual leave', 'annual_days' => 15, 'carry_over_days' => 5, 'is_paid' => true, 'enforce_balance' => true],
        ['slug' => 'sick', 'name' => 'Sick leave', 'annual_days' => null, 'carry_over_days' => 0, 'is_paid' => true, 'enforce_balance' => false],
        ['slug' => 'unpaid', 'name' => 'Unpaid leave', 'annual_days' => null, 'carry_over_days' => 0, 'is_paid' => false, 'enforce_balance' => false],
        ['slug' => 'other', 'name' => 'Other', 'annual_days' => null, 'carry_over_days' => 0, 'is_paid' => true, 'enforce_balance' => false],
    ],
];
