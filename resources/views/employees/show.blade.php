<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:760px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">{{ $employee->fullName() }}</h1>
            <p style="font-size:0.8rem;color:#52525b;margin:0;">{{ $employee->position?->title ?? 'No position assigned' }}</p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <a href="{{ route('employees.edit', $employee) }}" class="dot-btn dot-btn-ghost">Edit</a>
            <form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('Remove this employee record?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="dot-btn dot-btn-ghost" style="color:#ef4444;">Remove</button>
            </form>
        </div>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <div class="dot-card" style="padding:1.5rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Work Email</div>
            <div style="color:#d4d4d8;">{{ $employee->email ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Phone</div>
            <div style="color:#d4d4d8;">{{ $employee->phone ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Employment Type</div>
            <div style="color:#d4d4d8;">{{ ucfirst(str_replace('_', ' ', $employee->employment_type)) }}</div>
        </div>
        <div>
            <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Status</div>
            <span class="dot-badge dot-badge-accent">{{ ucfirst(str_replace('_', ' ', $employee->status)) }}</span>
        </div>
        <div>
            <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Start Date</div>
            <div style="color:#d4d4d8;">{{ optional($employee->start_date)->format('M j, Y') ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">End Date</div>
            <div style="color:#d4d4d8;">{{ optional($employee->end_date)->format('M j, Y') ?? '—' }}</div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.5rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:1rem;">Leave Requests</div>
        @if ($employee->leaveRequests->isEmpty())
            <p style="font-size:0.82rem;color:#52525b;">No leave requests on file.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:0.5rem;">
                @foreach ($employee->leaveRequests as $leaveRequest)
                    <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="display:flex;align-items:center;justify-content:space-between;padding:0.6rem 0.75rem;border-radius:8px;background:rgba(255,255,255,0.03);text-decoration:none;color:inherit;">
                        <span style="font-size:0.82rem;color:#d4d4d8;">{{ ucfirst($leaveRequest->type) }} &middot; {{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j, Y') }}</span>
                        <span class="dot-badge dot-badge-accent">{{ ucfirst($leaveRequest->status) }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
</x-app-layout>
