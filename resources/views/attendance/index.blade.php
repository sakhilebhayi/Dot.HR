<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($inNow->isEmpty())
            Nobody is clocked in right now.
        @else
            <em>{{ $inNow->count() }} {{ Str::plural('person', $inNow->count()) }}</em> clocked in right now.
        @endif
    </h1>
    <p class="rg-sub">Times only — Dot.HR records no location and no biometric data when people clock in.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($inNow->isNotEmpty())
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
            <div class="dot-label">In now</div>
            @foreach ($inNow as $record)
                <div class="rg-entry"><h4>{{ $record->employee?->fullName() }}</h4><span class="dot-note">since {{ $record->clock_in_at->format('D H:i') }}</span></div>
            @endforeach
        </div>
    @endif

    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:18px 0 10px;">
        <h2 style="font:400 22px var(--rg-serif);margin:0;">{{ $day->isSameDay(\App\Support\Hr\LocalTime::today()) ? 'Today' : $day->format('l j F') }}</h2>
        <form method="GET" action="{{ route('attendance.index') }}" style="display:flex;gap:6px;">
            <label for="day" style="position:absolute;left:-9999px;">Day</label>
            <input id="day" name="day" type="date" class="dot-input" value="{{ $day->toDateString() }}" style="width:auto;">
            <button type="submit" class="dot-btn">Show</button>
        </form>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        <div class="table-scroll">
            <table style="width:100%;">
                <thead><tr><th class="dot-th">Person</th><th class="dot-th">In</th><th class="dot-th">Out</th><th class="dot-th">Break</th><th class="dot-th">Worked</th><th class="dot-th">Recorded by</th><th class="dot-th"></th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td class="dot-td">{{ $record->employee?->fullName() }}</td>
                            <td class="dot-td dot-figure">{{ $record->clock_in_at->format('H:i') }}</td>
                            <td class="dot-td dot-figure">{{ $record->clock_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="dot-td dot-figure">{{ $record->break_minutes }}m</td>
                            <td class="dot-td dot-figure">{{ $record->clock_out_at ? \App\Models\Timesheet::hours($record->workedMinutes()) : 'in' }}</td>
                            <td class="dot-td dot-note">{{ $record->source === 'self' ? 'Themselves' : 'Supervisor' }}@if ($record->note) · {{ $record->note }}@endif</td>
                            <td class="dot-td">
                                <details>
                                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;min-height:28px;padding:2px 8px;">Correct</summary>
                                    <form method="POST" action="{{ route('attendance.update', $record) }}" style="display:grid;gap:6px;margin-top:6px;min-width:240px;">
                                        @csrf
                                        @method('PUT')
                                        <input type="datetime-local" name="clock_in_at" class="dot-input" value="{{ $record->clock_in_at->format('Y-m-d\TH:i') }}" aria-label="In" required>
                                        <input type="datetime-local" name="clock_out_at" class="dot-input" value="{{ $record->clock_out_at?->format('Y-m-d\TH:i') }}" aria-label="Out" required>
                                        <input type="number" name="break_minutes" min="0" max="240" class="dot-input" value="{{ $record->break_minutes }}" aria-label="Break minutes">
                                        <input name="note" class="dot-input" maxlength="255" placeholder="Why it changed" aria-label="Reason">
                                        <button type="submit" class="dot-btn">Save correction</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="dot-td dot-note" colspan="7">Nobody clocked in on this day.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <section class="rg-section">
        <h2>Record a missed stretch</h2>
        <form method="POST" action="{{ route('attendance.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;">
            @csrf
            <div class="rg-form-row">
                <div>
                    <label for="employee_id">Person</label>
                    <select id="employee_id" name="employee_id" class="dot-input" required>
                        @foreach ($employees as $person)<option value="{{ $person->id }}">{{ $person->first_name }} {{ $person->last_name }}</option>@endforeach
                    </select>
                </div>
                <div><label for="rec-note">Why it is recorded by hand</label><input id="rec-note" name="note" class="dot-input" maxlength="255" placeholder="Forgot to clock in"></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;" class="pay-row">
                <div><label for="rec-in">In</label><input id="rec-in" name="clock_in_at" type="datetime-local" class="dot-input" required></div>
                <div><label for="rec-out">Out</label><input id="rec-out" name="clock_out_at" type="datetime-local" class="dot-input" required></div>
                <div><label for="rec-break">Break (min)</label><input id="rec-break" name="break_minutes" type="number" min="0" max="240" value="0" class="dot-input"></div>
            </div>
            <div><button type="submit" class="dot-btn dot-btn-primary">Record</button></div>
            <p class="dot-note" style="margin:0;">Hand-recorded and corrected time is marked as such and written to the audit log. A week whose timesheet is approved is locked.</p>
        </form>
    </section>
</div>
<style>@media (max-width: 700px) { .pay-row { grid-template-columns: minmax(0,1fr) !important; } }</style>
</x-app-layout>
