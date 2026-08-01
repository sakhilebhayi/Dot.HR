<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">{{ $leaveRequest->employee->fullName() }}</h1>
            <p style="font-size:0.8rem;color:#52525b;margin:0;">{{ ucfirst($leaveRequest->type) }} leave &middot; {{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j, Y') }}</p>
        </div>
        <span class="dot-badge dot-badge-accent">{{ ucfirst($leaveRequest->status) }}</span>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Reason</div>
        <div style="color:#d4d4d8;font-size:0.85rem;">{{ $leaveRequest->reason ?: '—' }}</div>

        <div style="margin-top:1rem;font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Requested By</div>
        <div style="color:#d4d4d8;font-size:0.85rem;">{{ $leaveRequest->requestedBy->name }}</div>

        @if ($leaveRequest->reviewedBy)
        <div style="margin-top:1rem;font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Reviewed By</div>
        <div style="color:#d4d4d8;font-size:0.85rem;">{{ $leaveRequest->reviewedBy->name }} on {{ $leaveRequest->reviewed_at->format('M j, Y') }}</div>
        @endif
    </div>

    @if ($leaveRequest->status === 'pending')
    <div style="display:flex;gap:0.75rem;">
        <form method="POST" action="{{ route('leave-requests.approve', $leaveRequest) }}">
            @csrf
            <button type="submit" class="dot-btn dot-btn-primary">Approve</button>
        </form>
        <form method="POST" action="{{ route('leave-requests.deny', $leaveRequest) }}">
            @csrf
            <button type="submit" class="dot-btn dot-btn-ghost" style="color:#ef4444;">Deny</button>
        </form>
    </div>
    @endif
</div>
</x-app-layout>
