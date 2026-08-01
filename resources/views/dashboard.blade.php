<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Workforce Dashboard</h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem;">
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Headcount</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:var(--accent);">{{ $headcount }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">On Leave</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#f59e0b;">{{ $onLeaveCount }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Pending Leave Requests</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#ef4444;">{{ $pendingLeaveRequests }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Positions Defined</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#22c55e;">{{ $openPositions }}</div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;">Pending Leave Requests</div>
            <a href="{{ route('leave-requests.index') }}" class="dot-btn dot-btn-ghost">View all</a>
        </div>
        @if ($recentLeaveRequests->isEmpty())
            <p style="font-size:0.82rem;color:#52525b;">No pending leave requests.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:0.5rem;">
                @foreach ($recentLeaveRequests as $leaveRequest)
                    <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="display:flex;align-items:center;justify-content:space-between;padding:0.6rem 0.75rem;border-radius:8px;background:rgba(255,255,255,0.03);text-decoration:none;color:inherit;">
                        <span style="font-size:0.82rem;color:#d4d4d8;">{{ $leaveRequest->employee->fullName() }}</span>
                        <span style="font-size:0.75rem;color:#71717a;">{{ ucfirst($leaveRequest->type) }} &middot; {{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
</x-app-layout>
