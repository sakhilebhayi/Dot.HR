<x-app-layout>
@push('head')
    @vite(['resources/css/dashboard.css', 'resources/js/dashboard.js'])
@endpush
<div class="page-pad"><div class="dash">
    @php
        // The Register greets by name and leads with the one thing that matters.
        $firstName = str(auth()->user()->name)->before(' ');
        $hour = (int) now()->format('G');
        $daypart = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $company = auth()->user()->currentTeam->name;
        $emptyRegister = $role === 'admin' && $headcount === 0;
        $fmt = fn ($n) => rtrim(rtrim(number_format($n, 1), '0'), '.') ?: '0';
    @endphp
    <a class="dash-skip" href="#dash-main">Skip to the register</a>

    {{-- The landing: who you are, what Dot.HR is for here, and the one thing to do next.
         Exactly one element carries data-primary-action; everything else is quieter. --}}
    <header class="rg-land">
        @include('partials.dash-art', ['variant' => 'band'])
        <div class="rg-land-mast">
            <img class="rg-land-logo rg-land-logo--day" src="{{ asset('images/logo-trim.png') }}" alt="Dot.HR" width="99" height="88">
            <img class="rg-land-logo rg-land-logo--night" src="{{ asset('images/logo-light-trim.png') }}" alt="Dot.HR" width="99" height="88">
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

    <div id="dash-main" tabindex="-1">
    <x-flash />

    @if ($emptyRegister)
        <div class="dash-empty">
            <div>
                <h2>Nobody is on the register yet</h2>
                <p class="dot-note">Add the first person who works at {{ $company }}. Their record, leave and requests start from there, and this page fills in as people are added.</p>
            </div>
            @include('partials.dash-art', ['variant' => 'empty'])
        </div>
    @elseif ($role === 'admin' || $role === 'manager')
        <div class="dash-tally" role="group" aria-label="The register today">
            <a href="{{ route('employees.index') }}"><b>{{ $headcount }}</b> employed</a>
            <a href="{{ route('leave-requests.index') }}"><b>{{ $onLeaveCount }}</b> away today</a>
            @if ($role === 'admin')
                <a href="{{ route('positions.index') }}"><b>{{ $openPositions }}</b> {{ Str::plural('position', $openPositions) }}</a>
            @else
                <span><b>{{ $openPositions }}</b> {{ Str::plural('position', $openPositions) }}</span>
            @endif
            @if (($myOpenSteps ?? 0) > 0)
                <a href="{{ route('my-work.index') }}"><b>{{ $myOpenSteps }}</b> {{ Str::plural('step', $myOpenSteps) }} for you</a>
            @endif
        </div>

        <div class="dash-desk r-stack {{ $role === 'admin' && $recentLeaveRequests->isEmpty() ? 'solo' : '' }}">
            <div>
                @if ($role === 'admin')
                <section class="dash-sec" aria-labelledby="h-wait">
                    <div class="dash-sec-h">
                        <h2 id="h-wait">Needs a decision<span>{{ $recentLeaveRequests->count() }} shown of {{ $pendingLeaveRequests }}</span></h2>
                        <a href="{{ route('leave-requests.index', ['status' => 'pending']) }}">View all</a>
                    </div>
                    @forelse ($recentLeaveRequests as $leaveRequest)
                        @if ($loop->first)<ul class="dash-rows q" role="listbox" aria-label="Pending leave requests" data-queue>@endif
                        @php
                            $dates = $leaveRequest->start_date->format('M j').'–'.$leaveRequest->end_date->format('M j');
                            $canReason = auth()->user()->can('viewReason', $leaveRequest);
                        @endphp
                        <li class="dash-row l" role="option" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            data-name="{{ $leaveRequest->employee->fullName() }}"
                            data-type="{{ $leaveRequest->typeLabel() }}"
                            data-dates="{{ $dates }}"
                            data-days="{{ $fmt($leaveRequest->workingDays()) }}"
                            data-asked="{{ $leaveRequest->created_at->format('j M Y') }}"
                            data-status="{{ ucfirst($leaveRequest->status) }}"
                            @if ($canReason) data-reason="{{ $leaveRequest->reason ?: 'None given' }}" @endif
                            data-href="{{ route('leave-requests.show', $leaveRequest) }}">
                            <a class="go" href="{{ route('leave-requests.show', $leaveRequest) }}" aria-label="{{ $leaveRequest->employee->fullName() }}, {{ $leaveRequest->typeLabel() }}, {{ $dates }}"></a>
                            <span class="name">{{ $leaveRequest->employee->fullName() }}</span>
                            <span class="soft">{{ $leaveRequest->typeLabel() }}</span>
                            <span class="fig soft">{{ $dates }}</span>
                        </li>
                        @if ($loop->last)</ul>@endif
                    @empty
                        <p class="dot-note" style="margin:0;">No pending leave requests.</p>
                    @endforelse
                </section>
                @endif

                <section class="dash-sec" aria-labelledby="h-away">
                    <div class="dash-sec-h"><h2 id="h-away">Away today<span>{{ $onLeaveCount }}</span></h2>@if ($role === 'manager')<a href="{{ route('leave-requests.index') }}">Team leave</a>@endif</div>
                    @forelse ($awayToday as $person)
                        @if ($loop->first)<ul class="dash-rows n">@endif
                        <li class="dash-row">
                            <a class="go" href="{{ route('employees.show', $person) }}" aria-label="{{ $person->fullName() }}"></a>
                            <span class="label">{{ $person->fullName() }}</span>
                        </li>
                        @if ($loop->last)</ul>@endif
                    @empty
                        <p class="dot-note" style="margin:0;">Nobody is on approved leave today.</p>
                    @endforelse
                    @if ($role === 'manager')
                        <p class="dash-note">Names only. Why someone is away stays with HR.</p>
                    @endif
                </section>
            </div>

            @if ($role === 'admin' && $recentLeaveRequests->isNotEmpty())
                @php $first = $recentLeaveRequests->first(); @endphp
                <aside class="dash-pane" aria-label="Selected request" data-queue-pane>
                    <div class="dash-sec-h"><h2>Selected request</h2></div>
                    <div class="dash-pane-body">
                        <h2 class="t" data-fill="name" aria-live="polite">{{ $first->employee->fullName() }}</h2>
                        <dl class="dash-facts">
                            <div><dt>Leave</dt><dd data-fill="type">{{ $first->typeLabel() }}</dd></div>
                            <div><dt>Dates</dt><dd data-fill="dates">{{ $first->start_date->format('M j') }}–{{ $first->end_date->format('M j') }}</dd></div>
                            <div><dt>Working days</dt><dd data-fill="days">{{ $fmt($first->workingDays()) }}</dd></div>
                            @can('viewReason', $first)
                            <div data-reason-row><dt>Reason</dt><dd data-fill="reason" @class(['is-none' => ! $first->reason])>{{ $first->reason ?: 'None given' }}</dd></div>
                            @endcan
                            <div><dt>Asked on</dt><dd data-fill="asked">{{ $first->created_at->format('j M Y') }}</dd></div>
                            <div><dt>Status</dt><dd><span class="dot-badge dot-badge-warn" data-fill="status">{{ ucfirst($first->status) }}</span></dd></div>
                        </dl>
                        <div class="dash-btns"><a class="dot-btn" href="{{ route('leave-requests.show', $first) }}" data-fill-href>Open request</a></div>
                        <p class="dash-note">Approve or deny on the request page, where the balance and clashes are shown.</p>
                    </div>
                </aside>
            @elseif ($role === 'manager')
                <aside class="dash-pane" aria-label="What you can see">
                    <div class="dash-sec-h"><h2>What you can see here</h2></div>
                    <dl class="dash-facts">
                        <div><dt>Names</dt><dd>Everyone on the team</dd></div>
                        <div><dt>Leave</dt><dd>Dates and whether it is approved</dd></div>
                        <div><dt>Reasons</dt><dd>Not shown to managers</dd></div>
                        <div><dt>Contact details</dt><dd>Not shown to managers</dd></div>
                        <div><dt>Changes</dt><dd>Managers read; HR decides</dd></div>
                    </dl>
                </aside>
            @endif
        </div>
    @endif

    @if ($role === 'admin' && ! $emptyRegister || isset($hiring))
        <div class="dash-pair r-stack">
            @isset($people)
            <section aria-labelledby="h-people">
                <div class="dash-sec-h"><h2 id="h-people">People</h2><a href="{{ route('lifecycle.index') }}">Lifecycle</a></div>
                <ul class="dash-rows n">
                    @foreach ([['Joining', $people['joining'], 'lifecycle.index'], ['Leaving', $people['leaving'], 'lifecycle.index'], [Str::plural('Request', $people['openRequests']).' waiting for HR', $people['openRequests'], 'requests.index']] as [$label, $value, $to])
                    <li class="dash-row {{ $value === 0 ? 'zero' : '' }}"><a class="go" href="{{ route($to) }}" aria-label="{{ $label }}: {{ $value }}"></a><span class="label">{{ $label }}</span><span class="fig">{{ $value }}</span></li>
                    @endforeach
                </ul>
            </section>
            @endisset
            @isset($hiring)
            <section aria-labelledby="h-hire">
                <div class="dash-sec-h"><h2 id="h-hire">Hiring</h2><a href="{{ route('hiring.index') }}">Open hiring</a></div>
                <ul class="dash-rows n">
                    @foreach ([['Open '.Str::plural('role', $hiring['openRoles']), $hiring['openRoles']], ['New '.Str::plural('application', $hiring['newApplications']), $hiring['newApplications']], [Str::plural('Interview', $hiring['upcomingInterviews']).' this week', $hiring['upcomingInterviews']], [Str::plural('Offer', $hiring['offersOut']).' awaiting an answer', $hiring['offersOut']]] as [$label, $value])
                    <li class="dash-row {{ $value === 0 ? 'zero' : '' }}"><a class="go" href="{{ route('hiring.index') }}" aria-label="{{ $label }}: {{ $value }}"></a><span class="label">{{ $label }}</span><span class="fig">{{ $value }}</span></li>
                    @endforeach
                </ul>
            </section>
            @endisset
        </div>
    @endif

    @isset($mine)
        @if ($role === 'self')
        <div class="dash-tally" role="group" aria-label="For you">
            <a href="{{ route('my-work.index') }}"><b>{{ $myOpenSteps }}</b> {{ Str::plural('step', $myOpenSteps) }} for you</a>
            <a href="{{ route('leave-requests.index') }}"><b>{{ $myPendingCount }}</b> {{ Str::plural('request', $myPendingCount) }} pending</a>
        </div>
        @endif
        <div class="dash-desk r-stack even">
            <section class="dash-sec" aria-labelledby="h-bal">
                <div class="dash-sec-h">
                    <h2 id="h-bal">{{ $role === 'self' ? 'Your balance' : 'Your own balance' }}</h2>
                    {{-- For an employee the landing's primary action is this link; a linked admin or manager gets it here, quietly. --}}
                    @if ($role !== 'self')
                        <a href="{{ route('leave-requests.create') }}">Request leave</a>
                    @endif
                </div>
                @if ($myBalances->isEmpty())
                    <p class="dot-note" style="margin:0;">None of your leave types is counted against a yearly balance.</p>
                @else
                    <table class="dash-bal">
                        <thead><tr><th scope="col">Leave</th><th scope="col">Entitled</th><th scope="col">Taken</th><th scope="col">Pending</th><th scope="col">Left</th></tr></thead>
                        <tbody>
                        @foreach ($myBalances as $row)
                            <tr><td>{{ $row['type']->name }}</td><td>{{ $fmt($row['snapshot']->entitlement) }}</td><td>{{ $fmt($row['snapshot']->used) }}</td><td>{{ $fmt($row['snapshot']->pending) }}</td><td class="left">{{ $fmt($row['snapshot']->remaining) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    <ul class="dash-rows dash-balm">
                        @foreach ($myBalances as $row)
                        <li class="dash-row"><span class="name">{{ $row['type']->name }}</span><span class="soft">{{ $fmt($row['snapshot']->entitlement) }} entitled · {{ $fmt($row['snapshot']->used) }} taken · {{ $fmt($row['snapshot']->pending) }} pending</span><span class="fig">{{ $fmt($row['snapshot']->remaining) }} left</span></li>
                        @endforeach
                    </ul>
                    <p class="dash-note">Working days in the leave year that began {{ $myBalances->first()['snapshot']->yearStart->format('j M Y') }}. Weekends and configured public holidays are not counted.</p>
                @endif
            </section>
            <section class="dash-sec" aria-labelledby="h-up">
                <div class="dash-sec-h"><h2 id="h-up">Coming up</h2><a href="{{ route('leave-requests.index') }}">All my leave</a></div>
                @forelse ($myLeave as $leave)
                    @if ($loop->first)<ul class="dash-rows n">@endif
                    <li class="dash-row l">
                        <a class="go" href="{{ route('leave-requests.show', $leave) }}" aria-label="{{ $leave->typeLabel() }}, {{ $leave->start_date->format('M j') }} to {{ $leave->end_date->format('M j') }}, {{ $leave->status }}"></a>
                        <span class="label">{{ $leave->typeLabel() }} &middot; {{ $leave->start_date->format('M j') }}&ndash;{{ $leave->end_date->format('M j') }}</span>
                        <span class="fig"><span class="dot-badge {{ $leave->status === 'approved' ? 'dot-badge-ok' : 'dot-badge-warn' }}">{{ ucfirst($leave->status) }}</span></span>
                    </li>
                    @if ($loop->last)</ul>@endif
                @empty
                    <p class="dot-note" style="margin:0;">No upcoming leave.</p>
                @endforelse
                <p class="dash-note">Only you and HR see your leave type and reason.</p>
            </section>
        </div>
    @endisset

    @if ($role === 'none')
        <div class="dash-desk r-stack even">
            <section class="dash-sec" aria-labelledby="h-why">
                <div class="dash-sec-h"><h2 id="h-why">Your account is not linked to an employee record yet</h2></div>
                <p class="dash-note" style="margin-top:0;">
                    Leave and your own details appear here once an HR administrator links your account, or when the work email on your employee record matches the verified email you sign in with. Until then there is nothing in this workspace for you to see.
                </p>
                @include('partials.dash-art', ['variant' => 'empty'])
            </section>
            <section class="dash-sec" aria-labelledby="h-link">
                <div class="dash-sec-h"><h2 id="h-link">Two ways it gets linked</h2></div>
                <dl class="dash-facts">
                    <div><dt>By HR</dt><dd>An administrator links your account to your record.</dd></div>
                    <div><dt>By email</dt><dd>Your record's work email matches your verified sign-in email.</dd></div>
                </dl>
            </section>
        </div>
    @endif

    @if ($role !== 'none')
        <div class="dash-foot">Counts are live for {{ $company }}.</div>
    @endif
    </div>
</div>
</div>
</x-app-layout>
