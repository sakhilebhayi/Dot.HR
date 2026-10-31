{{-- One navigation, two workspaces (R-32 Phase 3). $style is 'top' (pill links in the top
     bar, setup pages under "More") or 'drawer' (every link as a row in the off-canvas drawer).
     The personal workspace shows the person's own career space; a business workspace shows
     HR for the current company. Each link appears only for those who may open it. --}}
@php
    $personal = request()->routeIs('me.*', 'jobs.*', 'people.*');
    $user = auth()->user();
    $access = $user ? \App\Support\Hr\HrAccess::current($user) : null;
    $primary = [];
    $more = [];

    if ($personal) {
        $primary[] = ['me.home', 'me.home', 'Home', 'home'];
        $primary[] = ['jobs.index', 'jobs.*', 'Find work', 'travel_explore'];
        $primary[] = ['me.applications.index', 'me.applications.*', 'Applications', 'assignment'];
        $primary[] = ['me.profile', 'me.profile', 'Profile', 'badge'];
    } else {
        $primary[] = ['dashboard', 'dashboard', 'Home', 'home'];
        if ($access?->employee()) {
            $primary[] = ['my-work.index', 'my-work.*', 'My work', 'person_check'];
        }
        if ($access?->canReviewCandidates()) {
            $primary[] = ['hiring.index', 'hiring.*', 'Hiring', 'work'];
        }
        if ($user?->can('viewAny', App\Models\Employee::class)) {
            $primary[] = ['employees.index', 'employees.*', $user->can('create', App\Models\Employee::class) ? 'Employees' : 'People', 'badge'];
        }
        if ($user?->can('viewAny', App\Models\LeaveRequest::class)) {
            $primary[] = ['leave-requests.index', 'leave-requests.*', 'Leave', 'event_busy'];
        }
        if ($access && ($access->canManageWorkforce() || $access->isManager())) {
            $primary[] = ['schedule.index', 'schedule.*', 'Schedule', 'calendar_month'];
        }
        if ($access?->canManageWorkforce()) {
            $more[] = ['attendance.index', 'attendance.*', 'Attendance', 'schedule'];
            $more[] = ['timesheets.index', 'timesheets.*', 'Timesheets', 'fact_check'];
            $more[] = ['certifications.index', 'certifications.*', 'Certifications', 'verified'];
            $more[] = ['training.index', 'training.*', 'Training', 'school'];
        }
        if ($access && ($access->isAdmin() || \App\Models\Review::query()->where('reviewer_id', $user->id)->exists())) {
            $more[] = ['reviews.index', 'reviews.*', 'Reviews', 'rate_review'];
        }
        if ($access?->isAdmin()) {
            $more[] = ['contractors.index', 'contractors.*', 'Contractors', 'handshake'];
        }
        if ($access?->isAdmin()) {
            $primary[] = ['lifecycle.index', 'lifecycle.*', 'Lifecycle', 'checklist'];
            $primary[] = ['requests.index', 'requests.*', 'Requests', 'inbox'];
        }
        if ($user?->can('viewAny', App\Models\Position::class)) {
            $more[] = ['positions.index', 'positions.*', 'Positions', 'account_tree'];
        }
        if ($user?->can('viewAny', App\Models\LeaveType::class)) {
            $more[] = ['leave-types.index', 'leave-types.*', 'Leave types', 'tune'];
        }
        if ($access?->canManageAccess()) {
            $more[] = ['access.index', 'access.*', 'Access', 'key'];
        }
        if ($user?->can('viewAny', App\Models\HrAuditLog::class)) {
            $more[] = ['audit-log.index', 'audit-log.*', 'Audit log', 'history'];
        }
    }
    $moreActive = collect($more)->contains(fn ($l) => request()->routeIs($l[1]));
@endphp

@if ($style === 'top')
    <nav class="topnav" aria-label="Primary">
        @foreach ($primary as [$route, $pattern, $label])
            <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
        @if ($more !== [])
            <details class="topnav-more">
                <summary class="{{ $moreActive ? 'active' : '' }}">More</summary>
                <div class="ws-menu" style="width:200px;">
                    @foreach ($more as [$route, $pattern, $label])
                        <a href="{{ route($route) }}" class="ws-item {{ request()->routeIs($pattern) ? 'ws-item--on' : '' }}"><span class="ws-name">{{ $label }}</span></a>
                    @endforeach
                </div>
            </details>
        @endif
    </nav>
@else
    <nav class="sidebar-nav">
        @foreach ([...$primary, ...$more] as [$route, $pattern, $label, $icon])
            <a href="{{ route($route) }}" class="nav-item {{ request()->routeIs($pattern) ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">{{ $icon }}</span>
                {{ $label }}
            </a>
        @endforeach
        <div class="sidebar-divider" style="margin:10px 0;"></div>
        @if ($personal)
            @if ($user?->currentTeam)
                <a href="{{ route('dashboard') }}" class="nav-item">
                    <span class="material-symbols-rounded nav-icon">apartment</span>
                    {{ $user->currentTeam->name }}
                </a>
            @endif
        @else
            <a href="{{ route('me.home') }}" class="nav-item">
                <span class="material-symbols-rounded nav-icon">person</span>
                My career
            </a>
        @endif
        <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
            <span class="material-symbols-rounded nav-icon">manage_accounts</span>
            Profile &amp; Settings
        </a>
    </nav>
@endif
