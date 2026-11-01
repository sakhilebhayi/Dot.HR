<x-app-layout>
<div class="page-pad">
    @php
        $total = $shiftsByDay->flatten()->count();
        $today = \App\Support\Hr\LocalTime::today();
        $empty = $shiftsByDay->flatten()->filter(fn ($s) => $s->employees->isEmpty())->count();
    @endphp
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div>
            <h1 class="rg-greet">
                Week of {{ $week->format('j F') }} —
                @if ($total === 0)
                    nothing scheduled yet.
                @else
                    <em>{{ $total }} {{ Str::plural('shift', $total) }}</em>@if ($empty > 0), <em>{{ $empty }}</em> with nobody on {{ $empty === 1 ? 'it' : 'them' }}.@else, all staffed.@endif
                @endif
            </h1>
        </div>
        <div style="display:flex;gap:6px;">
            <a href="{{ route('schedule.index', ['week' => $week->copy()->subWeek()->toDateString()]) }}" class="dot-btn">← Previous</a>
            <a href="{{ route('schedule.index') }}" class="dot-btn dot-btn-ghost">This week</a>
            <a href="{{ route('schedule.index', ['week' => $week->copy()->addWeek()->toDateString()]) }}" class="dot-btn">Next →</a>
        </div>
    </div>

    <div style="margin-top:18px;"><x-flash /></div>
    @if ($canManage)
        <p style="margin:0 0 12px;">
            <a href="{{ route('schedule.swaps') }}" class="dot-btn {{ $swapsWaiting ? 'dot-btn-primary' : 'dot-btn-ghost' }}">{{ $swapsWaiting ? $swapsWaiting.' shift '.Str::plural('swap', $swapsWaiting).' to approve' : 'Shift swaps' }}</a>
        </p>
    @endif
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div style="display:grid;gap:12px;">
        @foreach ($days as $day)
            @php $shifts = $shiftsByDay->get($day->toDateString(), collect()); @endphp
            <div class="dot-card dot-card--pad" style="{{ $day->isSameDay($today) ? 'outline:2px solid var(--rg-accent-soft);' : '' }}">
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;">
                    <div style="font-weight:600;">{{ $day->format('l j M') }} @if ($day->isSameDay($today))<span class="dot-badge dot-badge-accent">today</span>@endif</div>
                    <div class="dot-note">{{ $shifts->count() ? $shifts->count().' '.Str::plural('shift', $shifts->count()) : 'No shifts' }}</div>
                </div>
                @foreach ($shifts as $shift)
                    <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                        <div style="min-width:0;">
                            <h4>{{ $shift->window() }} @if ($shift->title)· {{ $shift->title }}@endif</h4>
                            <div class="m">
                                {{ \App\Models\Timesheet::hours($shift->paidMinutes()) }} paid
                                @if ($shift->break_minutes) · {{ $shift->break_minutes }} min break @endif
                                @if ($shift->location) · {{ $shift->location }} @endif
                            </div>
                            <div class="rg-chips" style="margin-top:8px;">
                                @forelse ($shift->employees as $person)
                                    <span class="rg-chip" @if (! $canManage) style="padding-right:11px;" @endif>
                                        {{ $person->fullName() }}
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('schedule.unassign', [$shift, $person->id]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" aria-label="Take {{ $person->fullName() }} off this shift">×</button>
                                            </form>
                                        @endif
                                    </span>
                                @empty
                                    <span class="dot-badge dot-badge-warn">Nobody on it</span>
                                @endforelse
                            </div>
                        </div>
                        @if ($canManage)
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                                <form method="POST" action="{{ route('schedule.assign', $shift) }}" style="display:flex;gap:6px;margin:0;">
                                    @csrf
                                    <label for="add-{{ $shift->id }}" style="position:absolute;left:-9999px;">Add someone</label>
                                    <select id="add-{{ $shift->id }}" name="employee_id" class="dot-input" style="width:auto;min-height:32px;padding:4px 8px;">
                                        @foreach ($employees as $person)
                                            @unless ($shift->employees->contains('id', $person->id))
                                                <option value="{{ $person->id }}">{{ $person->first_name }} {{ $person->last_name }}</option>
                                            @endunless
                                        @endforeach
                                    </select>
                                    <button type="submit" class="dot-btn" style="min-height:32px;">Add</button>
                                </form>
                                <form method="POST" action="{{ route('schedule.destroy', $shift) }}" style="margin:0;" data-confirm="Remove this shift?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="dot-btn dot-btn-danger" style="min-height:32px;">Remove</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    @if ($canManage)
        <section class="rg-section" id="new-shift">
            <h2>Add a shift</h2>
            <form method="POST" action="{{ route('schedule.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
                @csrf
                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;" class="pay-row">
                    <div><label for="date">Day</label><input id="date" name="date" type="date" class="dot-input" required value="{{ old('date', ($week->lt($today) ? $today : $week)->toDateString()) }}"></div>
                    <div><label for="start">From</label><input id="start" name="start" type="time" class="dot-input" required value="{{ old('start', '07:00') }}"></div>
                    <div><label for="end">To</label><input id="end" name="end" type="time" class="dot-input" required value="{{ old('end', '16:00') }}"></div>
                    <div><label for="break_minutes">Break (min)</label><input id="break_minutes" name="break_minutes" type="number" min="0" max="240" class="dot-input" value="{{ old('break_minutes', 60) }}"></div>
                </div>
                <p class="dot-note" style="margin:-4px 0 0;">An end time before the start is the next morning — a night shift.</p>
                <div class="rg-form-row">
                    <div><label for="title">What it is</label><input id="title" name="title" class="dot-input" maxlength="120" placeholder="Day shift, receiving bay"></div>
                    <div><label for="location">Where</label><input id="location" name="location" class="dot-input" maxlength="120" placeholder="Warehouse, Mahikeng"></div>
                </div>
                <fieldset style="border:0;padding:0;margin:0;">
                    <legend style="font-size:13px;font-weight:500;margin-bottom:6px;">Who works it</legend>
                    <div class="rg-checks">
                        @foreach ($employees as $person)
                            <label><input type="checkbox" name="employee_ids[]" value="{{ $person->id }}"> {{ $person->first_name }} {{ $person->last_name }}</label>
                        @endforeach
                    </div>
                    <p class="dot-note" style="margin:6px 0 0;">People already on an overlapping shift or on approved leave are refused, with the reason.</p>
                </fieldset>
                <div><button type="submit" class="dot-btn dot-btn-primary">Add the shift</button></div>
            </form>
        </section>
    @endif
</div>
<style>@media (max-width: 700px) { .pay-row { grid-template-columns: minmax(0,1fr) minmax(0,1fr) !important; } }</style>
</x-app-layout>
