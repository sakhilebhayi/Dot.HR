<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\Hr\Audit;

/**
 * Audit trail for employee records, on every code path (controller, console, factory),
 * so nothing can change a record without a trace. Never logs values of personal fields.
 */
class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        $fields = collect($employee->getAttributes())
            ->filter(fn ($v, $k) => $v !== null && ! in_array($k, ['id', 'team_id', 'created_at', 'updated_at', 'deleted_at', 'email_hash', 'created_by'], true))
            ->keys()->values()->all();

        Audit::record('employee.created', $employee, $fields);
    }

    public function updated(Employee $employee): void
    {
        $fields = Audit::changedFields($employee);

        if ($fields === []) {
            return;
        }

        $changes = Audit::diff($employee, $fields, Employee::AUDIT_FIELDS);

        $action = 'employee.updated';

        if ($fields === ['user_id']) {
            $action = $employee->user_id === null ? 'employee.unlinked' : 'employee.linked';
        }

        Audit::record($action, $employee, $fields, $changes);
    }

    public function deleted(Employee $employee): void
    {
        if (! $employee->isForceDeleting()) {
            Audit::record('employee.deleted', $employee);
        }
    }

    public function restored(Employee $employee): void
    {
        Audit::record('employee.restored', $employee);
    }

    public function forceDeleted(Employee $employee): void
    {
        Audit::record('employee.purged', $employee);
    }
}
