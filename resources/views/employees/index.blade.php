<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">Employees</h1>
            <p style="font-size:0.8rem;color:#52525b;margin:0;">{{ $employees->total() }} employee{{ $employees->total() !== 1 ? 's' : '' }}</p>
        </div>
        <a href="{{ route('employees.create') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:16px;">person_add</span>
            Add Employee
        </a>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <form method="GET" action="{{ route('employees.index') }}" class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:2fr 1fr auto;gap:0.75rem;align-items:end;">
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or email..." class="dot-input" />
        </div>
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:#52525b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Status</label>
            <select name="status" class="dot-input">
                <option value="">All</option>
                @foreach(['active', 'on_leave', 'terminated'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
    </form>

    <div class="dot-card" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,0.07);">
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Name</th>
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Position</th>
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Type</th>
                    <th style="padding:0.75rem 1rem;color:#71717a;font-weight:600;text-transform:uppercase;font-size:0.68rem;letter-spacing:0.06em;">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                    <td style="padding:0.7rem 1rem;">
                        <a href="{{ route('employees.show', $employee) }}" style="color:#d4d4d8;text-decoration:none;font-weight:600;">{{ $employee->fullName() }}</a>
                    </td>
                    <td style="padding:0.7rem 1rem;color:#a1a1aa;">{{ $employee->position?->title ?? '—' }}</td>
                    <td style="padding:0.7rem 1rem;color:#a1a1aa;">{{ ucfirst(str_replace('_', ' ', $employee->employment_type)) }}</td>
                    <td style="padding:0.7rem 1rem;">
                        <span class="dot-badge dot-badge-accent">{{ ucfirst(str_replace('_', ' ', $employee->status)) }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" style="padding:1.5rem 1rem;color:#52525b;text-align:center;">No employees yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1.25rem;">{{ $employees->links() }}</div>
</div>
</x-app-layout>
