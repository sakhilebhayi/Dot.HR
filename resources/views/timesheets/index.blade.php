<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($waiting->isEmpty())
            No timesheets are waiting.
        @else
            <em>{{ $waiting->count() }} {{ Str::plural('timesheet', $waiting->count()) }}</em> waiting for approval.
        @endif
    </h1>
    <p class="rg-sub">
        @if ($ordinaryHours)
            Time above {{ $ordinaryHours }} hours a week is flagged as overtime.
        @else
            Overtime is not flagged — set your ordinary weekly hours below if you want it to be.
        @endif
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        @forelse ($waiting as $sheet)
            <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                <div style="min-width:0;">
                    <h4>{{ $sheet->employee->fullName() }} — week of {{ $sheet->week_start->format('j M') }}</h4>
                    <div class="m">
                        {{ \App\Models\Timesheet::hours($sheet->worked_minutes) }} worked
                        @if ($sheet->overtime_minutes > 0) · <strong style="color:var(--rg-warn);">{{ \App\Models\Timesheet::hours($sheet->overtime_minutes) }} overtime</strong> @endif
                        · submitted {{ $sheet->submitted_at?->diffForHumans() }}
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                    <form method="POST" action="{{ route('timesheets.decide', [$sheet, 'approve']) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-primary">Approve</button></form>
                    <details>
                        <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Send back</summary>
                        <form method="POST" action="{{ route('timesheets.decide', [$sheet, 'reject']) }}" style="display:flex;gap:6px;margin-top:6px;">
                            @csrf
                            <input name="note" class="dot-input" maxlength="255" placeholder="What needs fixing" required aria-label="Reason">
                            <button type="submit" class="dot-btn">Send back</button>
                        </form>
                    </details>
                </div>
            </div>
        @empty
            <p class="dot-note" style="margin:0;">People submit their week from My work. Approved weeks lock their hours.</p>
        @endforelse
    </div>

    @if ($recent->isNotEmpty())
        <section class="rg-section">
            <h2>Recently decided</h2>
            <div class="dot-card dot-card--pad">
                @foreach ($recent as $sheet)
                    <div class="rg-entry">
                        <div><h4>{{ $sheet->employee->fullName() }} — week of {{ $sheet->week_start->format('j M') }}</h4><div class="m">{{ \App\Models\Timesheet::hours($sheet->worked_minutes) }} · {{ $sheet->decider?->name ?? '—' }} · {{ $sheet->decided_at?->format('j M') }}@if ($sheet->decision_note) · {{ $sheet->decision_note }}@endif</div></div>
                        <span class="dot-badge {{ $sheet->status === 'approved' ? 'dot-badge-ok' : 'dot-badge-warn' }}">{{ \App\Models\Timesheet::STATUSES[$sheet->status] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($isAdmin)
        <section class="rg-section">
            <h2>Ordinary weekly hours</h2>
            <form method="POST" action="{{ route('timesheets.settings') }}" class="dot-card dot-card--pad" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                @csrf
                @method('PUT')
                <div>
                    <label for="weekly_ordinary_hours">Hours a week before overtime</label>
                    <input id="weekly_ordinary_hours" name="weekly_ordinary_hours" type="number" min="1" max="80" class="dot-input" style="width:140px;" value="{{ $ordinaryHours }}" placeholder="Not set">
                </div>
                <button type="submit" class="dot-btn">Save</button>
                <p class="dot-note" style="margin:0;flex-basis:100%;">Use what your contracts and your jurisdiction say. Dot.HR flags the time; it does not calculate overtime pay.</p>
            </form>
        </section>
    @endif
</div>
</x-app-layout>
