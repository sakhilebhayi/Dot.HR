<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.2rem;">Positions</h1>
            <p class="dot-note" style="margin:0;">Roles in this team and how many people hold each. A position describes work, not a person.</p>
        </div>
        @can('create', App\Models\Position::class)
        <a href="{{ route('positions.create') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:16px;">add</span>
            Add position
        </a>
        @endcan
    </div>

    <x-flash />

    <div class="dot-card" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.07);">
                    <th class="dot-th">Title</th>
                    <th class="dot-th">Department</th>
                    <th class="dot-th" style="text-align:right;">People</th>
                    @can('create', App\Models\Position::class)<th class="dot-th"></th>@endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($positions as $position)
                <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                    <td class="dot-td" style="color:#e4e4e7;font-weight:600;">{{ $position->title }}</td>
                    <td class="dot-td" style="color:#a1a1aa;">{{ $position->department ?? '—' }}</td>
                    <td class="dot-td dot-figure" style="text-align:right;color:#a1a1aa;">{{ $position->employees_count }}</td>
                    @can('update', $position)
                    <td class="dot-td" style="text-align:right;white-space:nowrap;">
                        <a href="{{ route('positions.edit', $position) }}" class="dot-btn dot-btn-ghost">Edit</a>
                    </td>
                    @endcan
                </tr>
                @empty
                <tr><td colspan="4" class="dot-td" style="padding:1.5rem 1rem;color:#8a8a94;text-align:center;">No positions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-app-layout>
