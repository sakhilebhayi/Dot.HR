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
    | Retention for candidates, time records and notifications (R-32 Phase 8)
    |--------------------------------------------------------------------------
    | `php artisan hr:retention` (scheduled daily) permanently removes:
    |  - applications that ended without a hire (not progressing or withdrawn)
    |    longer ago than candidate_retention_days, with their notes, history,
    |    interviews and offers;
    |  - attendance, timesheets and shifts older than time_record_retention_days
    |    (default 3 years: South Africa's BCEA s31 asks employers to keep time
    |    records for three years);
    |  - read in-app notifications older than notification_retention_days.
    | Starting defaults, not legal advice: set them to what your obligations
    | require. Audit-log rows are never removed.
    */
    'candidate_retention_days' => (int) env('HR_CANDIDATE_RETENTION_DAYS', 365),
    'time_record_retention_days' => (int) env('HR_TIME_RECORD_RETENTION_DAYS', 365 * 3),
    'notification_retention_days' => (int) env('HR_NOTIFICATION_RETENTION_DAYS', 180),

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

    /*
    |--------------------------------------------------------------------------
    | Local time for rosters and attendance
    |--------------------------------------------------------------------------
    | Shifts are typed as wall-clock times, so clock-ins, "today" and week
    | boundaries are read in this timezone (the app itself runs on UTC). Set
    | HR_LOCAL_TIMEZONE to where the business works. South Africa has no DST.
    */
    'local_timezone' => env('HR_LOCAL_TIMEZONE', 'Africa/Johannesburg'),

    /*
    |--------------------------------------------------------------------------
    | Contractor classification notice
    |--------------------------------------------------------------------------
    | Shown wherever an engagement is created or viewed. Calling someone a
    | contractor does not decide whether they are legally an employee. The
    | default describes South Africa; set HR_CLASSIFICATION_NOTICE for another
    | jurisdiction. It is a prompt to take advice, not legal advice.
    */
    'classification_notice' => env('HR_CLASSIFICATION_NOTICE',
        'Calling someone a contractor does not decide whether they are legally an employee: how the work is actually done does. '
        .'In South Africa, section 200A of the Labour Relations Act presumes a person earning below the earnings threshold is an employee '
        .'when, among other things, you control their hours or how they work, they form part of your organisation, they work only for you '
        .'or depend on you economically, or you provide their tools. Where several of these apply, take advice.'),
];
