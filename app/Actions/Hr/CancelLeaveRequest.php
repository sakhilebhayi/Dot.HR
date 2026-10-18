<?php

namespace App\Actions\Hr;

use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;

/** Withdraws a still-pending request. Locks and re-reads the row so it cannot race a decision. */
class CancelLeaveRequest
{
    /**
     * @throws LeaveStateException
     */
    public function __invoke(LeaveRequest $leaveRequest): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest): LeaveRequest {
            $locked = LeaveRequest::whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new LeaveStateException('This request was already '.$locked->status.' and can no longer be cancelled.');
            }

            $locked->update(['status' => LeaveRequest::STATUS_CANCELLED]);

            return $locked;
        });
    }
}
