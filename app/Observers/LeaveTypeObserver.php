<?php

namespace App\Observers;

use App\Models\LeaveType;
use App\Services\Hr\Audit;

/** Entitlement and enforcement changes are policy decisions about people; they are logged (values are configuration, not personal data). */
class LeaveTypeObserver
{
    private const FIELDS = ['name', 'annual_days', 'carry_over_days', 'is_paid', 'enforce_balance'];

    public function created(LeaveType $type): void
    {
        Audit::record('leave_type.created', $type);
    }

    public function updated(LeaveType $type): void
    {
        $fields = Audit::changedFields($type);

        if ($fields !== []) {
            Audit::record('leave_type.updated', $type, $fields, Audit::diff($type, $fields, self::FIELDS));
        }
    }

    public function deleted(LeaveType $type): void
    {
        Audit::record('leave_type.deleted', $type);
    }
}
