<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification that a leave request needs
 * review. Not yet wired to any automatic trigger on LeaveRequestController's
 * store() — dispatch manually via `$user->notify(new
 * LeaveRequestSubmittedNotification($leaveRequest))` until a real
 * team-wide "notify approvers" flow exists (see wiki.md §9 roadmap).
 */
class LeaveRequestSubmittedNotification extends Notification
{
    public function __construct(public LeaveRequest $leaveRequest)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $employeeName = $this->leaveRequest->employee?->fullName() ?? 'An employee';

        return [
            'type' => 'leave_request_submitted',
            'title' => 'Leave request pending',
            'message' => "{$employeeName} requested ".ucfirst($this->leaveRequest->type)." leave from {$this->leaveRequest->start_date?->format('M d, Y')} to {$this->leaveRequest->end_date?->format('M d, Y')}.",
            'leave_request_id' => $this->leaveRequest->id,
            'url' => route('leave-requests.show', $this->leaveRequest),
        ];
    }
}
