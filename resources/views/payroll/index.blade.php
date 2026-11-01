<x-app-layout>
<div class="page-pad">
    @php
        $q = $month ? ['month' => $month] : ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $open = array_sum($checks);
        $starting = collect($rows)->whereNotNull('starts')->count();
        $leaving = collect($rows)->whereNotNull('leaves')->count();
        $hours = fn (int $m) => rtrim(rtrim(number_format($m / 60, 2, '.', ' '), '0'), '.') ?: '0';
        $days = fn (float $d) => rtrim(rtrim(number_format($d, 1, '.', ''), '0'), '.') ?: '0';
    @endphp
    <h1 class="rg-greet">
        <em>{{ count($rows) }} {{ Str::plural('person', count($rows)) }}</em> for payroll, {{ $from->format('j M') }} to {{ $to->format('j M Y') }}{{ $latest && ! $changed ? ', signed off.' : '.' }}
    </h1>
    <p class="rg-sub">What the payroll provider needs from HR for the period: who starts and who leaves, approved hours and overtime, and leave taken. Dot.HR holds no pay and calculates nothing. Contractors are paid by invoice and are left out.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;justify-content:space-between;margin:0 0 16px;">
        <form method="GET" action="{{ route('payroll.index') }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0;">
            <div><label for="pm">Month</label><input id="pm" name="month" type="month" class="dot-input" style="width:auto;" value="{{ $month ?? $from->format('Y-m') }}"></div>
            <button type="submit" class="dot-btn">Show</button>
            <a href="{{ route('payroll.index', ['month' => $from->subMonthNoOverflow()->format('Y-m')]) }}" class="dot-btn dot-btn-ghost">Previous month</a>
            <details>
                <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Other dates</summary>
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:8px;">
                    <div><label for="pf">From</label><input id="pf" name="from" type="date" class="dot-input" style="width:auto;" value="{{ $month ? '' : $from->toDateString() }}" form="period-dates"></div>
                    <div><label for="pt">To</label><input id="pt" name="to" type="date" class="dot-input" style="width:auto;" value="{{ $month ? '' : $to->toDateString() }}" form="period-dates"></div>
                    <button type="submit" class="dot-btn" form="period-dates">Show</button>
                </div>
            </details>
        </form>
        <form id="period-dates" method="GET" action="{{ route('payroll.index') }}" style="display:none;"></form>
        <a href="{{ route('payroll.export', $q) }}" class="dot-btn">Download CSV</a>
    </div>

    @if ($open > 0)
        <div class="dot-flash dot-flash-warn" role="status">
            <strong style="font-weight:600;">Still open in this period, so the figures can change.</strong>
            @if ($checks['timesheets'])<a href="{{ route('timesheets.index') }}">{{ $checks['timesheets'] }} {{ Str::plural('timesheet', $checks['timesheets']) }} waiting for approval</a>.@endif
            @if ($checks['leave'])<a href="{{ route('leave-requests.index') }}">{{ $checks['leave'] }} leave {{ Str::plural('request', $checks['leave']) }} waiting for a decision</a>.@endif
            @if ($checks['clockedIn'])<a href="{{ route('attendance.index') }}">{{ $checks['clockedIn'] }} {{ Str::plural('person', $checks['clockedIn']) }} still clocked in</a>.@endif
        </div>
    @elseif ($latest && $changed)
        <div class="dot-flash dot-flash-warn" role="status"><strong style="font-weight:600;">Something changed after the sign-off</strong> by {{ $latest->signer?->name ?? 'someone no longer here' }} on {{ $latest->signed_off_at->format('j M Y, H:i') }}. Check the rows below and sign off again before the provider closes payroll.</div>
    @elseif ($latest)
        <div class="dot-flash dot-flash-ok" role="status">Signed off by {{ $latest->signer?->name ?? 'someone no longer here' }} on {{ $latest->signed_off_at->format('j M Y, H:i') }}, {{ $latest->people }} {{ Str::plural('person', $latest->people) }}.@if ($latest->note) {{ $latest->note }}@endif Nothing has changed since.</div>
    @endif

    <div class="dot-card table-scroll" style="padding:4px 0;">
        <table style="width:100%;min-width:760px;">
            <caption style="position:absolute;left:-9999px;">Payroll inputs from {{ $from->format('j M') }} to {{ $to->format('j M Y') }}</caption>
            <thead>
                <tr>
                    <th class="dot-th" scope="col">Person</th>
                    <th class="dot-th" scope="col">Starts or leaves</th>
                    <th class="dot-th rg-num" scope="col">Approved hours</th>
                    <th class="dot-th rg-num" scope="col">Overtime</th>
                    <th class="dot-th rg-num" scope="col">Paid leave</th>
                    <th class="dot-th rg-num" scope="col">Unpaid leave</th>
                    <th class="dot-th" scope="col">To check</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    @php $e = $r['employee']; @endphp
                    <tr>
                        <td class="dot-td"><a href="{{ route('employees.show', $e) }}" style="color:inherit;font-weight:500;">{{ $e->fullName() }}</a><span class="dot-note" style="display:block;">{{ $e->position?->title ?? 'No position' }}{{ $e->employment_type === 'part_time' ? ', part-time' : '' }}</span></td>
                        <td class="dot-td">
                            @if ($r['starts'])<span style="display:block;">Starts {{ $r['starts']->format('j M') }}</span>@endif
                            @if ($r['leaves'])<span style="display:block;">Leaves {{ $r['leaves']->format('j M') }}</span><span class="dot-note">{{ $r['leaving'] === 'End date' ? 'End date on record' : 'Resigned, last day agreed' }}</span>@endif
                        </td>
                        <td class="dot-td rg-num {{ $r['worked'] ? '' : 'is-zero' }}">{{ $hours($r['worked']) }}</td>
                        <td class="dot-td rg-num {{ $r['overtime'] ? '' : 'is-zero' }}">{{ $hours($r['overtime']) }}</td>
                        <td class="dot-td rg-num {{ $r['paid'] ? '' : 'is-zero' }}">{{ $days($r['paid']) }}</td>
                        <td class="dot-td rg-num {{ $r['unpaid'] ? '' : 'is-zero' }}">{{ $days($r['unpaid']) }}</td>
                        <td class="dot-td">
                            @if ($r['unapproved'])<span class="dot-badge dot-badge-warn">{{ $r['unapproved'] }} {{ Str::plural('week', $r['unapproved']) }} not approved</span>@endif
                            @if ($r['untyped'] > 0)<span class="dot-badge dot-badge-warn">{{ $days($r['untyped']) }} leave {{ Str::plural('day', (int) ceil($r['untyped'])) }} with no type</span>@endif
                            @if ($r['byType'] !== [])<span class="dot-note" style="display:block;">{{ implode(', ', array_map(fn ($n, $d) => $n.' '.$days($d), array_keys($r['byType']), $r['byType'])) }}</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="dot-td" colspan="7"><span class="dot-note">Nobody on the payroll in this period.</span></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rg-grid" style="margin-top:20px;">
        <section class="s8">
            <h2 style="font:400 22px var(--rg-serif);margin:0 0 10px;">How it is counted</h2>
            <ul class="dot-note" style="margin:0;padding-left:18px;display:grid;gap:6px;max-width:68ch;">
                <li>A timesheet week belongs to the period its Monday falls in. Only approved weeks count.</li>
                <li>Overtime is time above {{ $ordinaryHours ? $ordinaryHours.' hours a week' : 'the ordinary weekly hours, which are not set yet, so it shows as 0' }}, as set in Timesheets.</li>
                <li>Leave is the working days of approved leave inside the period. Whether it is paid comes from the leave type.</li>
                <li>Someone leaves in the period when their end date, or the agreed last day of a resignation HR received, falls in it.</li>
                <li>@if ($starting || $leaving){{ $starting }} {{ Str::plural('person', $starting) }} {{ $starting === 1 ? 'starts' : 'start' }} and {{ $leaving }} {{ $leaving === 1 ? 'leaves' : 'leave' }} in this period.@else Nobody starts or leaves in this period.@endif</li>
            </ul>
        </section>
        <section class="s4">
            <form method="POST" action="{{ route('payroll.sign-off', $q) }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;">
                @csrf
                <h2 style="font:400 22px var(--rg-serif);margin:0;">Sign off this period</h2>
                <p class="dot-note" style="margin:0;">Confirms these are the inputs to send. If anything changes afterwards, this page says so.</p>
                <div><label for="so-note">Note (optional)</label><input id="so-note" name="note" class="dot-input" maxlength="255" placeholder="Sent to the payroll provider on the 25th"></div>
                <div><button type="submit" class="dot-btn dot-btn-primary" @disabled($open > 0)>Sign off</button></div>
                @if ($open > 0)<p class="dot-note" style="margin:0;">Settle what is still open first.</p>@endif
            </form>
            @if ($recent->isNotEmpty())
                <div class="dot-card dot-card--pad" style="margin-top:14px;">
                    <div class="dot-label">Recent sign-offs</div>
                    @foreach ($recent as $run)
                        <a href="{{ route('payroll.index', $run->period_start->isSameDay($run->period_start->copy()->startOfMonth()) && $run->period_end->isSameDay($run->period_start->copy()->endOfMonth()) ? ['month' => $run->period_start->format('Y-m')] : ['from' => $run->period_start->toDateString(), 'to' => $run->period_end->toDateString()]) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $run->period_start->format('j M') }} to {{ $run->period_end->format('j M Y') }}</h4><div class="m">{{ $run->signer?->name ?? 'Someone no longer here' }}, {{ $run->signed_off_at->format('j M Y') }}</div></div>
                            <span class="dot-note">{{ $run->people }} {{ Str::plural('person', $run->people) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
</x-app-layout>
