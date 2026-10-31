<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($requested->isNotEmpty())
            <em>{{ $requested->count() }} training {{ Str::plural('request', $requested->count()) }}</em> waiting for a decision.
        @elseif ($planned->isNotEmpty())
            <em>{{ $planned->count() }}</em> booked, nothing waiting for a decision.
        @else
            No training is booked or asked for.
        @endif
    </h1>
    <p class="rg-sub">Requests from people, bookings and completions. Completed training with a certificate goes on file and closes the gap in readiness.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($requested->isNotEmpty())
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
            <div class="dot-label">Asked for</div>
            @foreach ($requested as $record)
                <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                    <div style="min-width:0;">
                        <h4>{{ $record->employee->fullName() }} — {{ $record->title }}</h4>
                        <div class="m">{{ \App\Models\TrainingRecord::KINDS[$record->kind] }}@if ($record->provider) · {{ $record->provider }}@endif @if ($record->reason) · “{{ $record->reason }}”@endif</div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                        <form method="POST" action="{{ route('training.decide', [$record, 'approve']) }}" style="display:flex;gap:6px;margin:0;">
                            @csrf
                            <label for="start-{{ $record->id }}" style="position:absolute;left:-9999px;">Date</label>
                            <input id="start-{{ $record->id }}" name="starts_on" type="date" class="dot-input" style="width:auto;min-height:36px;">
                            <button type="submit" class="dot-btn dot-btn-primary">Approve</button>
                        </form>
                        <details>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Decline</summary>
                            <form method="POST" action="{{ route('training.decide', [$record, 'decline']) }}" style="display:flex;gap:6px;margin-top:6px;">
                                @csrf
                                <input name="note" class="dot-input" maxlength="255" required placeholder="Why" aria-label="Why it is declined">
                                <button type="submit" class="dot-btn">Decline</button>
                            </form>
                        </details>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Booked</div>
                @forelse ($planned as $record)
                    <details class="rg-entry" style="display:block;">
                        <summary style="list-style:none;cursor:pointer;display:flex;justify-content:space-between;gap:10px;align-items:baseline;">
                            <span style="min-width:0;"><strong style="font-weight:500;">{{ $record->employee->fullName() }} — {{ $record->title }}</strong><span class="dot-note" style="display:block;">{{ $record->starts_on?->format('j M Y') ?? 'No date yet' }}@if ($record->certification_name) · leads to {{ $record->certification_name }}@endif</span></span>
                            <span class="dot-btn dot-btn-ghost" style="min-height:30px;">Record completion</span>
                        </summary>
                        <form method="POST" action="{{ route('training.complete', $record) }}" class="rg-form-row" style="margin-top:10px;align-items:end;">
                            @csrf
                            <div><label for="done-{{ $record->id }}">Completed on</label><input id="done-{{ $record->id }}" name="completed_on" type="date" class="dot-input" required value="{{ \App\Support\Hr\LocalTime::today()->toDateString() }}"></div>
                            <div><label for="hours-{{ $record->id }}">Hours</label><input id="hours-{{ $record->id }}" name="hours" type="number" step="0.5" min="0" class="dot-input"></div>
                            <div><label for="cert-{{ $record->id }}">Certificate it gives (optional)</label><input id="cert-{{ $record->id }}" name="certification_name" class="dot-input" maxlength="140" value="{{ $record->certification_name }}"></div>
                            <div style="display:flex;gap:6px;align-items:end;">
                                <div style="flex:1;"><label for="exp-{{ $record->id }}">Certificate expires</label><input id="exp-{{ $record->id }}" name="certification_expires_on" type="date" class="dot-input"></div>
                                <button type="submit" class="dot-btn dot-btn-primary">Completed</button>
                            </div>
                        </form>
                    </details>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nothing booked.</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('training.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;" id="book">
                @csrf
                <div class="dot-label">Book training</div>
                <div class="rg-form-row">
                    <div>
                        <label for="employee_id">For</label>
                        <select id="employee_id" name="employee_id" class="dot-input" required>
                            @foreach ($employees as $person)<option value="{{ $person->id }}" @selected($prefill['employee_id'] === $person->id)>{{ $person->first_name }} {{ $person->last_name }}</option>@endforeach
                        </select>
                    </div>
                    <div><label for="title">Course</label><input id="title" name="title" class="dot-input" maxlength="160" required value="{{ old('title', $prefill['certification_name']) }}"></div>
                </div>
                <div class="rg-form-row">
                    <div><label for="provider">Run by</label><input id="provider" name="provider" class="dot-input" maxlength="140"></div>
                    <div><label for="kind">Kind</label><select id="kind" name="kind" class="dot-input">@foreach (\App\Models\TrainingRecord::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                </div>
                <div class="rg-form-row">
                    <div><label for="starts_on">Date</label><input id="starts_on" name="starts_on" type="date" class="dot-input"></div>
                    <div><label for="certification_name">Certificate it gives (optional)</label><input id="certification_name" name="certification_name" class="dot-input" maxlength="140" value="{{ old('certification_name', $prefill['certification_name']) }}" placeholder="Match the position requirement's name"></div>
                </div>
                <div><button type="submit" class="dot-btn dot-btn-primary">Book it</button></div>
            </form>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            @if ($suggestions !== [])
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Could fill an open role with training</div>
                    <p class="dot-note" style="margin:4px 0 0;">People here who already hold at least half of what an open role asks for.</p>
                    @foreach ($suggestions as $s)
                        <a href="{{ route('training.index', ['employee_id' => $s['employee']->id, 'for' => $s['missing'][0]]) }}#book" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $s['employee']->fullName() }} → {{ $s['vacancy']->title }}</h4><div class="m">has {{ $s['matched'] }} of {{ $s['total'] }}; missing {{ implode(', ', $s['missing']) }}</div></div>
                            <span class="dot-note">Book</span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($gaps->isNotEmpty())
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Gaps training could close</div>
                    @foreach ($gaps as $gap)
                        @foreach ([...$gap['expired'], ...$gap['missing'], ...$gap['soon']] as $name)
                            <a href="{{ route('training.index', ['employee_id' => $gap['employee']->id, 'for' => $name]) }}#book" class="rg-entry" style="text-decoration:none;color:inherit;">
                                <div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $gap['employee']->fullName() }}</h4><div class="m">{{ $name }}</div></div>
                                <span class="dot-note">Book</span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Recently completed</div>
                @forelse ($completed as $record)
                    <div class="rg-entry"><div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $record->employee->fullName() }} — {{ $record->title }}</h4><div class="m">{{ $record->completed_on?->format('j M Y') }}@if ($record->hours) · {{ rtrim(rtrim($record->hours, '0'), '.') }}h @endif</div></div></div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-app-layout>
