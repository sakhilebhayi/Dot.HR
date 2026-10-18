<x-app-layout>
@php $display = $employee->displayStatus(); @endphp
<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:760px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">{{ $employee->fullName() }}</h1>
            <p class="dot-note" style="margin:0;">{{ $employee->position?->title ?? 'No position assigned' }}</p>
        </div>
        @if (! $employee->trashed())
        <div style="display:flex;gap:0.5rem;">
            @can('update', $employee)
            <a href="{{ route('employees.edit', $employee) }}" class="dot-btn dot-btn-ghost">Edit</a>
            @endcan
            @can('delete', $employee)
            <form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('Remove this employee? The record and their leave history are kept for {{ config('hr.retention_days') }} days and an admin can restore it until then.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="dot-btn dot-btn-danger">Remove</button>
            </form>
            @endcan
        </div>
        @endif
    </div>

    <x-flash />

    @if ($employee->trashed())
        <div class="dot-flash dot-flash-err">This record was removed on {{ $employee->deleted_at->format('j M Y') }} and will be deleted for good after the retention period.</div>
    @endif

    <div class="dot-card r-stack" style="padding:1.5rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <div class="dot-label">Employment type</div>
            <div style="color:#e4e4e7;">{{ ucfirst(str_replace('_', ' ', $employee->employment_type)) }}</div>
        </div>
        <div>
            <div class="dot-label">Status</div>
            <span class="dot-badge {{ $display === 'on_leave' ? 'dot-badge-warn' : ($display === 'terminated' ? 'dot-badge-muted' : 'dot-badge-accent') }}">{{ ucfirst(str_replace('_', ' ', $display)) }}</span>
        </div>
        @if ($canSeeContact)
        <div>
            <div class="dot-label">Work email</div>
            <div style="color:#e4e4e7;">{{ $employee->email ?? '—' }}</div>
        </div>
        <div>
            <div class="dot-label">Work phone</div>
            <div style="color:#e4e4e7;">{{ $employee->phone ?? '—' }}</div>
        </div>
        <div>
            <div class="dot-label">Start date</div>
            <div style="color:#e4e4e7;">{{ optional($employee->start_date)->format('M j, Y') ?? '—' }}</div>
        </div>
        <div>
            <div class="dot-label">End date</div>
            <div style="color:#e4e4e7;">{{ optional($employee->end_date)->format('M j, Y') ?? '—' }}</div>
        </div>
        @else
        <p class="dot-note" style="grid-column:1 / -1;margin:0;">Contact details, employment dates and leave history are visible to HR administrators and to the employee only.</p>
        @endif
    </div>

    @can('link', $employee)
    @unless ($employee->trashed())
    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:0.35rem;">Sign-in account</div>
        @if ($employee->user)
            <p style="margin:0 0 0.75rem;color:#e4e4e7;font-size:0.85rem;">Linked to {{ $employee->user->name }} ({{ $employee->user->email }}). They see only this record and their own leave.</p>
            <form method="POST" action="{{ route('employees.unlink', $employee) }}" onsubmit="return confirm('Unlink this account? They will no longer see this record or request leave.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="dot-btn dot-btn-ghost">Unlink account</button>
            </form>
        @else
            <p class="dot-note" style="margin:0 0 0.75rem;">Not linked. A linked person can see their own record and request leave. It links by itself when a team member signs in with a verified email that matches this record; otherwise choose the person here.</p>
            @if ($linkableUsers->isEmpty())
                <p class="dot-note" style="margin:0;">Every team member is already linked to a record.</p>
            @else
            <form method="POST" action="{{ route('employees.link', $employee) }}" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap;">
                @csrf
                <div style="flex:1;min-width:220px;">
                    <label class="dot-label" for="user_id">Team member</label>
                    <select id="user_id" name="user_id" class="dot-input" required>
                        <option value="">— Select —</option>
                        @foreach ($linkableUsers as $member)
                        <option value="{{ $member->id }}">{{ $member->name }} ({{ $member->email }})</option>
                        @endforeach
                    </select>
                    <x-input-error for="user_id" class="mt-2" />
                </div>
                <button type="submit" class="dot-btn dot-btn-primary">Link to account</button>
            </form>
            @endif
        @endif
    </div>
    @endunless
    @endcan

    @if ($canSeeContact)
    <div class="dot-card" style="padding:1.5rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:1rem;">Leave</div>
        @if ($leaveRequests->isEmpty())
            <p class="dot-note" style="margin:0;">No leave requests on file.</p>
        @else
            @foreach ($leaveRequests as $leaveRequest)
                <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="display:flex;align-items:center;justify-content:space-between;padding:0.6rem 0;border-top:1px solid rgba(255,255,255,0.06);text-decoration:none;color:inherit;">
                    <span style="font-size:0.85rem;color:#e4e4e7;">{{ $leaveRequest->typeLabel() }} &middot; {{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j, Y') }}</span>
                    <span class="dot-badge {{ ['approved' => 'dot-badge-ok', 'pending' => 'dot-badge-warn', 'denied' => 'dot-badge-bad'][$leaveRequest->status] ?? 'dot-badge-muted' }}">{{ ucfirst($leaveRequest->status) }}</span>
                </a>
            @endforeach
        @endif
    </div>
    @endif
</div>
</x-app-layout>
