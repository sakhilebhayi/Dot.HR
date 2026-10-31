<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="margin:0 0 0.2rem;">{{ $heading }}</h1>
            <p class="dot-note" style="margin:0;">{{ $leaveRequests->total() }} request{{ $leaveRequests->total() !== 1 ? 's' : '' }}</p>
        </div>
        @can('create', App\Models\LeaveRequest::class)
        <a href="{{ route('leave-requests.create') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
            Request leave
        </a>
        @endcan
    </div>

    <x-flash />

    <form method="GET" action="{{ route('leave-requests.index') }}" class="dot-card r-stack" style="padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:end;max-width:400px;">
        <div>
            <label class="dot-label" for="status">Status</label>
            <select id="status" name="status" class="dot-input">
                <option value="">All</option>
                @foreach(['pending', 'approved', 'denied', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
    </form>

    <div class="dot-card table-scroll" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="border-bottom:1px solid var(--rg-rule);">
                    <th class="dot-th">Employee</th>
                    @if ($showType)<th class="dot-th">Type</th>@endif
                    <th class="dot-th">Dates</th>
                    <th class="dot-th" style="text-align:right;">Days</th>
                    <th class="dot-th">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leaveRequests as $leaveRequest)
                <tr style="border-bottom:1px solid var(--rg-rule);">
                    <td class="dot-td">
                        <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="color:var(--rg-ink);text-decoration:none;font-weight:600;">{{ $leaveRequest->employee->fullName() }}</a>
                    </td>
                    @if ($showType)<td class="dot-td" style="color:var(--rg-muted);">{{ $leaveRequest->typeLabel() }}</td>@endif
                    <td class="dot-td" style="color:var(--rg-muted);">{{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j, Y') }}@if ($leaveRequest->half_day) <span class="dot-note">({{ strtoupper($leaveRequest->half_day) }})</span>@endif</td>
                    <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ rtrim(rtrim(number_format($leaveRequest->workingDays(), 1), '0'), '.') ?: '0' }}</td>
                    <td class="dot-td">
                        <span class="dot-badge {{ ['approved' => 'dot-badge-ok', 'pending' => 'dot-badge-warn', 'denied' => 'dot-badge-bad'][$leaveRequest->status] ?? 'dot-badge-muted' }}">{{ ucfirst($leaveRequest->status) }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ $showType ? 5 : 4 }}" class="dot-td" style="padding:1.5rem 1rem;color:#8a8a94;text-align:center;">No leave requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1.25rem;">{{ $leaveRequests->links() }}</div>
</div>
</x-app-layout>
