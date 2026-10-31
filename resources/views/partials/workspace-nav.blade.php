{{-- One navigation, two workspaces (R-32 Phase 3). $style is 'top' (pill links in the top
     bar) or 'drawer' (rows in the off-canvas drawer). The personal workspace shows the
     person's own career space; a business workspace shows HR for the current company. --}}
@php
    $personal = request()->routeIs('me.*', 'jobs.*', 'people.*');
    $access = auth()->check() ? \App\Support\Hr\HrAccess::current(auth()->user()) : null;
    $links = [];

    if ($personal) {
        $links[] = ['me.home', 'me.home', 'Home', 'home'];
        $links[] = ['jobs.index', 'jobs.*', 'Find work', 'travel_explore'];
        $links[] = ['me.applications.index', 'me.applications.*', 'Applications', 'assignment'];
        $links[] = ['me.profile', 'me.profile', 'Profile', 'badge'];
    } else {
        $links[] = ['dashboard', 'dashboard', 'Home', 'home'];
        if ($access?->canReviewCandidates()) {
            $links[] = ['hiring.index', 'hiring.*', 'Hiring', 'work'];
        }
        if (auth()->user()?->can('viewAny', App\Models\Employee::class)) {
            $links[] = ['employees.index', 'employees.*', auth()->user()->can('create', App\Models\Employee::class) ? 'Employees' : 'People', 'badge'];
        }
        if (auth()->user()?->can('viewAny', App\Models\LeaveRequest::class)) {
            $links[] = ['leave-requests.index', 'leave-requests.*', 'Leave', 'event_busy'];
        }
        if (auth()->user()?->can('viewAny', App\Models\Position::class)) {
            $links[] = ['positions.index', 'positions.*', 'Positions', 'account_tree'];
        }
        if (auth()->user()?->can('viewAny', App\Models\LeaveType::class)) {
            $links[] = ['leave-types.index', 'leave-types.*', 'Leave types', 'tune'];
        }
        if ($access?->canManageAccess()) {
            $links[] = ['access.index', 'access.*', 'Access', 'key'];
        }
        if (auth()->user()?->can('viewAny', App\Models\HrAuditLog::class)) {
            $links[] = ['audit-log.index', 'audit-log.*', 'Audit log', 'history'];
        }
    }
@endphp

@if ($style === 'top')
    <nav class="topnav" aria-label="Primary">
        @foreach ($links as [$route, $pattern, $label])
            <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>
@else
    <nav class="sidebar-nav">
        @foreach ($links as [$route, $pattern, $label, $icon])
            <a href="{{ route($route) }}" class="nav-item {{ request()->routeIs($pattern) ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">{{ $icon }}</span>
                {{ $label }}
            </a>
        @endforeach
        <div class="sidebar-divider" style="margin:10px 0;"></div>
        @if ($personal)
            @if (auth()->user()?->currentTeam)
                <a href="{{ route('dashboard') }}" class="nav-item">
                    <span class="material-symbols-rounded nav-icon">apartment</span>
                    {{ auth()->user()->currentTeam->name }}
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
