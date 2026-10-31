<x-app-layout>
<div class="page-pad">
    @php
        // The Register greets by name and leads with the one thing that matters.
        $firstName = str(auth()->user()->name)->before(' ');
        $hour = (int) now()->format('G');
        $daypart = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    @endphp
    <div style="margin-bottom:1.6rem;">
        @if ($role === 'admin')
            <h1 class="rg-greet">{{ $daypart }}, {{ $firstName }} —
                @if ($pendingLeaveRequests > 0)
                    <em>{{ $pendingLeaveRequests }} {{ Str::plural('request', $pendingLeaveRequests) }}</em> {{ $pendingLeaveRequests === 1 ? 'is' : 'are' }} waiting on you.
                @else
                    nothing is waiting on you.
                @endif
            </h1>
        @elseif ($role === 'manager')
            <h1 class="rg-greet">{{ $daypart }}, {{ $firstName }} —
                @if ($onLeaveCount > 0)<em>{{ $onLeaveCount }}</em> {{ $onLeaveCount === 1 ? 'person is' : 'people are' }} away today.
                @else everyone is in today.
                @endif
            </h1>
        @else
            <h1 class="rg-greet">{{ $daypart }}, {{ $firstName }}.</h1>
        @endif
        <p class="rg-sub" style="margin-bottom:0;">{{ now()->format('l, F j, Y') }} · {{ auth()->user()->currentTeam->name ?? 'Personal' }}</p>
    </div>

    <x-flash />

    @if ($role === 'admin' || $role === 'manager')
        <div class="dot-card" style="display:grid;grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem;overflow:hidden;">
            @foreach ([['Employed', $headcount], ['Away today', $onLeaveCount], ['Positions', $openPositions]] as [$label, $value])
            <div style="padding:1rem 1.25rem;{{ ! $loop->first ? 'border-left:1px solid var(--rg-rule);' : '' }}">
                <div class="dot-label">{{ $label }}</div>
                <div class="dot-figure" style="font-size:1.75rem;color:var(--rg-ink);">{{ str_pad((string) $value, 2, '0', STR_PAD_LEFT) }}</div>
            </div>
            @endforeach
        </div>

        <div class="dot-card r-stack r-stack-divide" style="display:grid;grid-template-columns:{{ $role === 'admin' ? '3fr 2fr' : '1fr' }};overflow:hidden;">
            @if ($role === 'admin')
            <div style="padding:1.25rem 1.5rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <div style="font-size:0.95rem;font-weight:600;color:var(--rg-ink);">Needs a decision</div>
                    <a href="{{ route('leave-requests.index', ['status' => 'pending']) }}" class="dot-btn dot-btn-ghost">View all</a>
                </div>
                @forelse ($recentLeaveRequests as $leaveRequest)
                    <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.6rem 0;border-top:1px solid var(--rg-rule);text-decoration:none;color:inherit;">
                        <span style="font-size:0.85rem;color:var(--rg-ink);">{{ $leaveRequest->employee->fullName() }}</span>
                        <span class="dot-note">{{ $leaveRequest->typeLabel() }} &middot; {{ $leaveRequest->start_date->format('M j') }}&ndash;{{ $leaveRequest->end_date->format('M j') }}</span>
                    </a>
                @empty
                    <p class="dot-note" style="margin:0;">No pending leave requests.</p>
                @endforelse
            </div>
            @endif
            <div style="padding:1.25rem 1.5rem;{{ $role === 'admin' ? 'border-left:1px solid var(--rg-rule);' : '' }}">
                <div style="font-size:0.95rem;font-weight:600;color:var(--rg-ink);margin-bottom:1rem;">Away today</div>
                @forelse ($awayToday as $person)
                    <div style="padding:0.5rem 0;border-top:1px solid var(--rg-rule);font-size:0.85rem;color:var(--rg-ink);">{{ $person->fullName() }}</div>
                @empty
                    <p class="dot-note" style="margin:0;">Nobody is on approved leave today.</p>
                @endforelse
            </div>
        </div>
    @endif

    @isset($mine)
        <div class="dot-card" style="margin-top:{{ $role === 'self' ? '0' : '1.25rem' }};padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
                <div style="font-size:0.95rem;font-weight:600;color:var(--rg-ink);">
                    {{ $role === 'self' ? 'Your balance' : 'Your own balance' }}
                </div>
                <a href="{{ route('leave-requests.create') }}" class="dot-btn dot-btn-primary">Request leave</a>
            </div>

            @if ($myBalances->isEmpty())
                <p class="dot-note" style="margin:0 0 1rem;">None of your leave types is counted against a yearly balance.</p>
            @else
            <div class="table-scroll">
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-bottom:1.25rem;">
                <thead>
                    <tr><th class="dot-th" style="padding-left:0;">Leave</th><th class="dot-th" style="text-align:right;">Entitled</th><th class="dot-th" style="text-align:right;">Taken</th><th class="dot-th" style="text-align:right;">Pending</th><th class="dot-th" style="text-align:right;">Left</th></tr>
                </thead>
                <tbody>
                @foreach ($myBalances as $row)
                    <tr style="border-top:1px solid var(--rg-rule);">
                        <td class="dot-td" style="padding-left:0;color:var(--rg-ink);">{{ $row['type']->name }}</td>
                        <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ rtrim(rtrim(number_format($row['snapshot']->entitlement, 1), '0'), '.') }}</td>
                        <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ rtrim(rtrim(number_format($row['snapshot']->used, 1), '0'), '.') ?: '0' }}</td>
                        <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ rtrim(rtrim(number_format($row['snapshot']->pending, 1), '0'), '.') ?: '0' }}</td>
                        <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-ink);">{{ rtrim(rtrim(number_format($row['snapshot']->remaining, 1), '0'), '.') ?: '0' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <p class="dot-note" style="margin:0 0 1.25rem;">Working days in the leave year that began {{ $myBalances->first()['snapshot']->yearStart->format('j M Y') }}. Weekends and configured public holidays are not counted.</p>
            @endif

            <div class="dot-label">Coming up</div>
            @forelse ($myLeave as $leave)
                <a href="{{ route('leave-requests.show', $leave) }}" style="display:flex;align-items:center;justify-content:space-between;padding:0.6rem 0;border-top:1px solid var(--rg-rule);text-decoration:none;color:inherit;">
                    <span style="font-size:0.85rem;color:var(--rg-ink);">{{ $leave->typeLabel() }} &middot; {{ $leave->start_date->format('M j') }}&ndash;{{ $leave->end_date->format('M j') }}</span>
                    <span class="dot-badge {{ $leave->status === 'approved' ? 'dot-badge-ok' : 'dot-badge-warn' }}">{{ ucfirst($leave->status) }}</span>
                </a>
            @empty
                <p class="dot-note" style="margin:0;">No upcoming leave.</p>
            @endforelse
        </div>
    @endisset

    @if ($role === 'none')
        <div class="dot-card" style="padding:1.5rem;max-width:560px;">
            <div style="font-size:0.95rem;font-weight:600;color:var(--rg-ink);margin-bottom:0.5rem;">Your account is not linked to an employee record yet</div>
            <p class="dot-note" style="margin:0;font-size:0.82rem;">
                Leave and your own details appear here once an HR administrator links your account, or when the work email on your employee record matches the verified email you sign in with. Until then there is nothing in this workspace for you to see.
            </p>
        </div>
    @endif
</div>
</x-app-layout>
