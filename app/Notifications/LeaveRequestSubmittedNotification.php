<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification that a leave request needs
 * review. Fired automatically for every admin/owner of the leave
 * request's team by App\Observers\LeaveRequestObserver, registered
 * against LeaveRequest in AppServiceProvider::boot() — recipients are
 * approvers only (LeaveRequestPolicy::isTeamAdmin()'s same predicate),
 * not every team member.
 */
class LeaveRequestSubmittedNotification extends Notification
{
    public function __construct(public LeaveRequest $leaveRequest) {}

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
