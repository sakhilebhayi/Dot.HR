<x-app-layout>
@push('head')
    <style>
        /* The landing (R-50): the greeting, what Dot.HR is for in this business and one
           primary action, with the original logo and the way back to InfoDot beside it.
           Sent with the document, like the shell, so the first paint is the finished one.
           Tokens come from register.css. */
        .rg-land {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start;
            column-gap: clamp(24px, 5vw, 64px); margin-bottom: 34px;
        }
        .rg-land-lead { grid-column: 1; grid-row: 1; min-width: 0; }
        .rg-land-mast { grid-column: 2; grid-row: 1; display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
        .rg-land-logo { display: block; height: 112px; width: auto; }
        .rg-land-logo--night { display: none; }
        html.dark .rg-land-logo--day { display: none; }
        html.dark .rg-land-logo--night { display: block; }
        .rg-land-back { display: flex; gap: 2px; margin-right: -10px; }
        .rg-land-back a {
            display: inline-flex; align-items: center; min-height: 36px; padding: 0 10px; border-radius: 8px;
            font-size: 13.5px; font-weight: 500; color: var(--rg-muted); text-decoration: none;
        }
        .rg-land-back a:hover { color: var(--rg-ink); background: var(--rg-panel); }
        .rg-land .rg-greet em { white-space: nowrap; }
        .rg-land .rg-greet { font-size: clamp(30px, 4.6vw, 46px); line-height: 1.08; text-wrap: balance; overflow-wrap: break-word; }
        .rg-land-copy { margin: 14px 0 0; max-width: 56ch; font-size: 15.5px; line-height: 1.55; color: var(--rg-ink); text-wrap: pretty; }
        .rg-land-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 24px; }
        .rg-land-go { min-height: 46px; padding: 11px 22px; font-size: 15px; font-weight: 600; margin-right: 6px; }
        .rg-land-date { margin-left: auto; }
        .rg-land-empty { margin-bottom: 1.25rem; max-width: 620px; }
        .rg-land-empty-title { font-size: 0.95rem; font-weight: 600; color: var(--rg-ink); margin-bottom: 4px; }
        .rg-land-empty p { margin: 0; }
        @media (max-width: 720px) {
            .rg-land { grid-template-columns: minmax(0, 1fr); row-gap: 18px; }
            .rg-land-mast { grid-column: 1; grid-row: 1; flex-direction: row; align-items: center; justify-content: space-between; }
            .rg-land-lead { grid-row: 2; }
            .rg-land-logo { height: 68px; }
            .rg-land-back a, .rg-land-actions .dot-btn { min-height: 44px; }
            .rg-land-go { flex: 1 1 100%; justify-content: center; margin-right: 0; }
            .rg-land-date { margin-left: 0; flex-basis: 100%; }
        }
        /* The one authored moment: the greeting and its action settle into place together.
           The resting state is the finished one and the first frame is already readable, so
           the page is whole with scripts blocked, motion turned down or a paused tab. */
        @keyframes rg-arrive { from { opacity: 0.4; transform: translateY(8px); } }
        @media (prefers-reduced-motion: no-preference) {
            .rg-land-lead { animation: rg-arrive 460ms cubic-bezier(0.16, 1, 0.3, 1) both; }
        }
    </style>
@endpush
<div class="page-pad">
    @php
        // The Register greets by name and leads with the one thing that matters.
        $firstName = str(auth()->user()->name)->before(' ');
        $hour = (int) now()->format('G');
        $daypart = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $company = auth()->user()->currentTeam->name;
        $emptyRegister = $role === 'admin' && $headcount === 0;
    @endphp
    {{-- The landing: who you are, what Dot.HR is for here, and the one thing to do next.
         Exactly one element carries data-primary-action; everything else is quieter. --}}
    <header class="rg-land">
        <div class="rg-land-mast">
            <img class="rg-land-logo rg-land-logo--day" src="{{ asset('images/logo-trim.png') }}" alt="Dot.HR" width="126" height="112">
            <img class="rg-land-logo rg-land-logo--night" src="{{ asset('images/logo-light-trim.png') }}" alt="Dot.HR" width="126" height="112">
            <nav class="rg-land-back" aria-label="Dot ecosystem">
                <a href="{{ $landing['infodot'] }}">InfoDot</a>
                <a href="{{ $landing['platforms'] }}">Platforms</a>
            </nav>
        </div>
        <div class="rg-land-lead">
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
            <p class="rg-land-copy">
                @if ($role === 'admin')
                    Dot.HR is the staff register for {{ $company }}: everyone who works here, the leave they take and the HR work that looks after them.
                @elseif ($role === 'manager')
                    Dot.HR is the staff register for {{ $company }}. You can see who is on the team and when they are away.
                @elseif ($role === 'self')
                    Dot.HR is where you ask {{ $company }} for leave, see what you have left and keep your own record up to date.
                @else
                    Dot.HR is the staff register for {{ $company }}. Your sign-in is not linked to an employee record here yet.
                @endif
            </p>
            <div class="rg-land-actions">
                <a href="{{ $landing['primary']['url'] }}" class="dot-btn dot-btn-primary rg-land-go" data-primary-action>{{ $landing['primary']['label'] }}</a>
                @foreach ($landing['secondary'] as $action)
                    <a href="{{ $action['url'] }}" class="dot-btn dot-btn-ghost">{{ $action['label'] }}</a>
                @endforeach
                <span class="dot-note rg-land-date">{{ now()->format('l, j F Y') }}</span>
            </div>
        </div>
    </header>

    <x-flash />

    @if ($emptyRegister)
        <div class="dot-card dot-card--pad rg-land-empty">
            <div class="rg-land-empty-title">Nobody is on the register yet</div>
            <p class="dot-note">Add the first person who works at {{ $company }}. Their record, leave and requests start from there, and this page fills in as people are added.</p>
        </div>
    @elseif ($role === 'admin' || $role === 'manager')
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

    @isset($people)
        @if ($people['joining'] + $people['leaving'] + $people['openRequests'] > 0)
        <div class="dot-card dot-card--pad" style="margin-top:1.25rem;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
            <div>
                <div style="font-weight:600;">People</div>
                <div class="dot-note">{{ $people['joining'] }} joining · {{ $people['leaving'] }} leaving · {{ $people['openRequests'] }} {{ Str::plural('request', $people['openRequests']) }} waiting for HR</div>
            </div>
            <span style="display:flex;gap:6px;">
                <a href="{{ route('lifecycle.index') }}" class="dot-btn">Lifecycle</a>
                <a href="{{ route('requests.index') }}" class="dot-btn">Requests</a>
            </span>
        </div>
        @endif
    @endisset

    @if (($myOpenSteps ?? 0) > 0)
        <div class="dot-card dot-card--pad" style="margin-top:1.25rem;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
            <div>
                <div style="font-weight:600;">{{ $myOpenSteps }} {{ Str::plural('step', $myOpenSteps) }} for you</div>
                <div class="dot-note">Your onboarding or offboarding needs something from you.</div>
            </div>
            <a href="{{ route('my-work.index') }}" class="dot-btn">Open My work</a>
        </div>
    @endif

    @isset($hiring)
        <div class="dot-card dot-card--pad" style="margin-top:1.25rem;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
            <div>
                <div style="font-weight:600;">Hiring</div>
                <div class="dot-note">
                    {{ $hiring['openRoles'] }} open {{ Str::plural('role', $hiring['openRoles']) }}
                    · {{ $hiring['newApplications'] }} new {{ Str::plural('application', $hiring['newApplications']) }}
                    · {{ $hiring['upcomingInterviews'] }} {{ Str::plural('interview', $hiring['upcomingInterviews']) }} this week
                    · {{ $hiring['offersOut'] }} {{ Str::plural('offer', $hiring['offersOut']) }} awaiting an answer
                </div>
            </div>
            <a href="{{ route('hiring.index') }}" class="dot-btn">Open hiring</a>
        </div>
    @endisset

    @isset($mine)
        <div class="dot-card" style="margin-top:{{ $role === 'self' ? '0' : '1.25rem' }};padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
                <div style="font-size:0.95rem;font-weight:600;color:var(--rg-ink);">
                    {{ $role === 'self' ? 'Your balance' : 'Your own balance' }}
                </div>
                {{-- For an employee the landing's primary action is this link; a linked admin or manager gets it here, quietly. --}}
                @if ($role !== 'self')
                    <a href="{{ route('leave-requests.create') }}" class="dot-btn">Request leave</a>
                @endif
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
