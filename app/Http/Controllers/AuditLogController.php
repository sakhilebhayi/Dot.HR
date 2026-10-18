<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Admin-only, read-only view of the audit log for the current team. There is no way to edit it here or anywhere. */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', HrAuditLog::class);

        $query = HrAuditLog::with('actor')->latest('id');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($actor = $request->query('actor')) {
            $query->where('actor_user_id', (int) $actor);
        }

        $subjects = [
            'employee' => Employee::class,
            'leaverequest' => LeaveRequest::class,
            'position' => Position::class,
            'leavetype' => LeaveType::class,
        ];

        if (isset($subjects[$request->query('subject')])) {
            $query->where('subject_type', $subjects[$request->query('subject')]);
        }

        if ($from = $this->date($request->query('from'))) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($to = $this->date($request->query('to'))) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        return view('audit-log.index', [
            'entries' => $query->paginate(50)->withQueryString(),
            'actions' => HrAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'actors' => $request->user()->currentTeam->allUsers()->sortBy('name')->values(),
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
