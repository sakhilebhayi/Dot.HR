<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="margin:0 0 0.2rem;">Leave types</h1>
            <p class="dot-note" style="margin:0;">What people can take, how many working days a year, and whether a request beyond the balance is refused.</p>
        </div>
        <a href="{{ route('leave-types.create') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:16px;">add</span>
            Add leave type
        </a>
    </div>

    <x-flash />
    @error('type')<div class="dot-flash dot-flash-err" role="alert">{{ $message }}</div>@enderror

    <div class="dot-card table-scroll" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="border-bottom:1px solid var(--rg-rule);">
                    <th class="dot-th">Type</th>
                    <th class="dot-th" style="text-align:right;">Days a year</th>
                    <th class="dot-th" style="text-align:right;">Carry over (max)</th>
                    <th class="dot-th">Paid</th>
                    <th class="dot-th">Beyond balance</th>
                    <th class="dot-th" style="text-align:right;">Requests</th>
                    <th class="dot-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($types as $type)
                <tr style="border-bottom:1px solid var(--rg-rule);">
                    <td class="dot-td" style="color:var(--rg-ink);font-weight:600;">{{ $type->name }}</td>
                    <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ $type->annual_days === null ? 'not counted' : rtrim(rtrim(number_format($type->annual_days, 1), '0'), '.') }}</td>
                    <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ $type->isTracked() ? rtrim(rtrim(number_format($type->carry_over_days, 1), '0'), '.') ?: '0' : '—' }}</td>
                    <td class="dot-td" style="color:var(--rg-muted);">{{ $type->is_paid ? 'Paid' : 'Unpaid' }}</td>
                    <td class="dot-td" style="color:var(--rg-muted);">{{ ! $type->isTracked() ? '—' : ($type->enforce_balance ? 'Refused' : 'Allowed') }}</td>
                    <td class="dot-td dot-figure" style="text-align:right;color:var(--rg-muted);">{{ $type->requests_count }}</td>
                    <td class="dot-td" style="text-align:right;white-space:nowrap;">
                      <div style="display:flex;gap:0.4rem;justify-content:flex-end;">
                        <a href="{{ route('leave-types.edit', $type) }}" class="dot-btn dot-btn-ghost">Edit</a>
                        @if ($type->requests_count === 0)
                        <form method="POST" action="{{ route('leave-types.destroy', $type) }}" onsubmit="return confirm('Delete this leave type?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="dot-btn dot-btn-danger">Delete</button>
                        </form>
                        @endif
                      </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="dot-note" style="margin-top:1rem;max-width:640px;">
        Balances count working days: weekends ({{ collect(config('hr.weekend_days'))->map(fn ($d) => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$d])->implode(', ') ?: 'none' }}) and {{ count(config('hr.public_holidays')) }} configured public holiday(s) are skipped, and the leave year starts in month {{ config('hr.leave_year_start_month') }}. Those are deployment settings (HR_WEEKEND_DAYS, HR_PUBLIC_HOLIDAYS, HR_LEAVE_YEAR_START_MONTH).
    </p>
</div>
</x-app-layout>
