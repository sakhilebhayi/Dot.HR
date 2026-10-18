<x-app-layout>
@php $isAdmin = auth()->user()->can('create', App\Models\Employee::class); @endphp
<div class="page-pad" style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">{{ $showRemoved ? 'Removed employees' : ($isAdmin ? 'Employees' : 'People') }}</h1>
            <p class="dot-note" style="margin:0;">
                {{ $employees->total() }} {{ $showRemoved ? 'removed record'.($employees->total() !== 1 ? 's' : '') : 'employee'.($employees->total() !== 1 ? 's' : '') }}
                @if ($showRemoved)
                    &middot; kept for {{ config('hr.retention_days') }} days after removal, then deleted for good
                @endif
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            @if ($isAdmin)
                <a href="{{ route('employees.index', $showRemoved ? [] : ['removed' => 1]) }}" class="dot-btn dot-btn-ghost">{{ $showRemoved ? 'Back to employees' : 'Removed' }}</a>
            @endif
            @can('create', App\Models\Employee::class)
            <a href="{{ route('employees.create') }}" class="dot-btn dot-btn-primary">
                <span class="material-symbols-rounded" style="font-size:16px;">person_add</span>
                Add Employee
            </a>
            @endcan
        </div>
    </div>

    <x-flash />

    @if ($isAdmin || $employees->total() > 1 || request()->hasAny(['search', 'status']))
    <form method="GET" action="{{ route('employees.index') }}" class="dot-card r-stack" style="padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:2fr 1fr auto;gap:0.75rem;align-items:end;">
        @if ($showRemoved)<input type="hidden" name="removed" value="1" />@endif
        <div>
            <label class="dot-label" for="search">Search</label>
            <input id="search" type="text" name="search" value="{{ request('search') }}" placeholder="{{ $isAdmin ? 'Name, or an exact email address' : 'Name' }}" class="dot-input" />
        </div>
        <div>
            <label class="dot-label" for="status">Status</label>
            <select id="status" name="status" class="dot-input">
                <option value="">All</option>
                @foreach(['active' => 'At work', 'on_leave' => 'On leave', 'terminated' => 'Terminated'] as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
    </form>
    @endif

    <div class="dot-card table-scroll" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.07);">
                    <th class="dot-th">Name</th>
                    <th class="dot-th">Position</th>
                    <th class="dot-th">Type</th>
                    <th class="dot-th">Status</th>
                    @if ($showRemoved)<th class="dot-th">Removed</th><th class="dot-th"></th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                @php $display = $employee->displayStatus(); @endphp
                <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                    <td class="dot-td">
                        @if ($showRemoved)
                            <span style="color:#e4e4e7;font-weight:600;">{{ $employee->fullName() }}</span>
                        @else
                            <a href="{{ route('employees.show', $employee) }}" style="color:#e4e4e7;text-decoration:none;font-weight:600;">{{ $employee->fullName() }}</a>
                        @endif
                    </td>
                    <td class="dot-td" style="color:#a1a1aa;">{{ $employee->position?->title ?? '—' }}</td>
                    <td class="dot-td" style="color:#a1a1aa;">{{ ucfirst(str_replace('_', ' ', $employee->employment_type)) }}</td>
                    <td class="dot-td">
                        <span class="dot-badge {{ $display === 'on_leave' ? 'dot-badge-warn' : ($display === 'terminated' ? 'dot-badge-muted' : 'dot-badge-accent') }}">{{ ucfirst(str_replace('_', ' ', $display)) }}</span>
                    </td>
                    @if ($showRemoved)
                    <td class="dot-td dot-figure" style="color:#a1a1aa;">{{ $employee->deleted_at->format('Y-m-d') }}</td>
                    <td class="dot-td">
                        <form method="POST" action="{{ route('employees.restore', $employee->id) }}">
                            @csrf
                            <button type="submit" class="dot-btn dot-btn-ghost">Restore</button>
                        </form>
                    </td>
                    @endif
                </tr>
                @empty
                <tr><td colspan="{{ $showRemoved ? 6 : 4 }}" class="dot-td" style="padding:1.5rem 1rem;color:#8a8a94;text-align:center;">{{ $showRemoved ? 'No removed records.' : 'No employees yet.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1.25rem;">{{ $employees->links() }}</div>
</div>
</x-app-layout>
