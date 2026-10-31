<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;">
        <div>
            <h1 style="margin:0 0 0.2rem;">{{ $leaveRequest->employee->fullName() }}</h1>
            <p class="dot-note" style="margin:0;">
                @if ($canSeeReason){{ $leaveRequest->typeLabel() }} &middot; @endif
                {{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j, Y') }}
                @if ($leaveRequest->half_day) &middot; {{ $leaveRequest->half_day === 'am' ? 'morning' : 'afternoon' }} only @endif
                &middot; {{ rtrim(rtrim(number_format($leaveRequest->workingDays(), 1), '0'), '.') ?: '0' }} working day(s)
            </p>
        </div>
        <span class="dot-badge {{ ['approved' => 'dot-badge-ok', 'pending' => 'dot-badge-warn', 'denied' => 'dot-badge-bad'][$leaveRequest->status] ?? 'dot-badge-muted' }}">{{ ucfirst($leaveRequest->status) }}</span>
    </div>

    <x-flash />

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <div class="dot-label">Reason</div>
        @if ($canSeeReason)
            <div style="color:var(--rg-ink);font-size:0.85rem;white-space:pre-line;">{{ $leaveRequest->reason ?: '—' }}</div>
        @else
            <div class="dot-note">Not shown to your role. HR administrators and the employee can read it.</div>
        @endif

        <div class="dot-label" style="margin-top:1rem;">Requested by</div>
        <div style="color:var(--rg-ink);font-size:0.85rem;">{{ $leaveRequest->requestedBy?->name ?? 'A former user' }}</div>

        @if ($leaveRequest->reviewedBy)
        <div class="dot-label" style="margin-top:1rem;">Decided by</div>
        <div style="color:var(--rg-ink);font-size:0.85rem;">{{ $leaveRequest->reviewedBy->name }} on {{ $leaveRequest->reviewed_at->format('M j, Y') }}</div>
        @endif
    </div>

    @if ($leaveRequest->status === 'pending')
        @if ($decision->allowed())
        <div style="display:flex;gap:0.75rem;">
            <form method="POST" action="{{ route('leave-requests.approve', $leaveRequest) }}">
                @csrf
                <button type="submit" class="dot-btn dot-btn-primary">Approve</button>
            </form>
            <form method="POST" action="{{ route('leave-requests.deny', $leaveRequest) }}">
                @csrf
                <button type="submit" class="dot-btn dot-btn-danger">Deny</button>
            </form>
        </div>
        @elseif ($decision->message())
            <p class="dot-note" style="margin:0;">This is your own request, so someone else decides it. A second admin is needed to approve or deny it.</p>
        @endif

        @if ($canCancel)
        <form method="POST" action="{{ route('leave-requests.cancel', $leaveRequest) }}" style="margin-top:0.75rem;" onsubmit="return confirm('Cancel this request?');">
            @csrf
            <button type="submit" class="dot-btn dot-btn-ghost">Cancel request</button>
        </form>
        @endif
    @endif

    @if ($canDelete)
    <form method="POST" action="{{ route('leave-requests.destroy', $leaveRequest) }}" style="margin-top:1.5rem;" onsubmit="return confirm('Remove this leave record? It is kept for {{ config('hr.retention_days') }} days, then deleted for good.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="dot-btn dot-btn-ghost" style="color:#fca5a5;">Remove record</button>
    </form>
    @endif
</div>
</x-app-layout>
