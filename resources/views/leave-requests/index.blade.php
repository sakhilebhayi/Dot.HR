<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">Leave Requests</h1>
            <p style="font-size:0.8rem;color:#52525b;margin:0;">{{ $leaveRequests->total() }} request{{ $leaveRequests->total() !== 1 ? 's' : '' }}</p>
        </div>
        <a href="{{ route('leave-requests.create') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
            New Request
        </a>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <form method="GET" action="{{ route('leave-requests.index') }}" class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:end;max-width:400px;">
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Status</label>
            <select name="status" class="dot-input">
                <option value="">All</option>
                @foreach(['pending', 'approved', 'denied'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
    </form>

    <div class="dot-card" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,0.07);">
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Employee</th>
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Type</th>
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Dates</th>
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leaveRequests as $leaveRequest)
                <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                    <td style="padding:0.7rem 1rem;">
                        <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="color:#d4d4d8;text-decoration:none;font-weight:600;">{{ $leaveRequest->employee->fullName() }}</a>
                    </td>
                    <td style="padding:0.7rem 1rem;color:#a1a1aa;">{{ ucfirst($leaveRequest->type) }}</td>
                    <td style="padding:0.7rem 1rem;color:#a1a1aa;">{{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j, Y') }}</td>
                    <td style="padding:0.7rem 1rem;">
                        <span class="dot-badge dot-badge-accent">{{ ucfirst($leaveRequest->status) }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" style="padding:1.5rem 1rem;color:#52525b;text-align:center;">No leave requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1.25rem;">{{ $leaveRequests->links() }}</div>
</div>
</x-app-layout>
