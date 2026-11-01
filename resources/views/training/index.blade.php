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
                            <label for="cost-{{ $record->id }}" style="position:absolute;left:-9999px;">Cost</label>
                            <input id="cost-{{ $record->id }}" name="cost" type="number" step="0.01" min="0" class="dot-input" style="width:110px;min-height:36px;" placeholder="Cost (ZAR)">
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
                            <span style="min-width:0;"><strong style="font-weight:500;">{{ $record->employee->fullName() }} — {{ $record->title }}</strong><span class="dot-note" style="display:block;">{{ $record->starts_on?->format('j M Y') ?? 'No date yet' }}@if ($record->certification_name) · leads to {{ $record->certification_name }}@endif @if ($record->costLabel()) · {{ $record->costLabel() }}@endif</span></span>
                            <span class="dot-btn dot-btn-ghost" style="min-height:30px;">Record completion</span>
                        </summary>
                        <form method="POST" action="{{ route('training.complete', $record) }}" class="rg-form-row" style="margin-top:10px;align-items:end;">
                            @csrf
                            <div><label for="done-{{ $record->id }}">Completed on</label><input id="done-{{ $record->id }}" name="completed_on" type="date" class="dot-input" required value="{{ \App\Support\Hr\LocalTime::today()->toDateString() }}"></div>
                            <div><label for="hours-{{ $record->id }}">Hours</label><input id="hours-{{ $record->id }}" name="hours" type="number" step="0.5" min="0" class="dot-input"></div>
                            <div><label for="actual-{{ $record->id }}">What it cost in the end ({{ $record->currency }})</label><input id="actual-{{ $record->id }}" name="cost" type="number" step="0.01" min="0" class="dot-input" value="{{ $record->cost }}"></div>
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
                <div class="rg-form-row rg-form-row--3">
                    <div><label for="cost">Cost (optional)</label><input id="cost" name="cost" type="number" step="0.01" min="0" class="dot-input" value="{{ old('cost') }}" placeholder="4500"></div>
                    <div><label for="currency">Currency</label><input id="currency" name="currency" class="dot-input" maxlength="3" value="{{ old('currency', 'ZAR') }}"></div>
                    <div><label for="paid_by">Paid by</label><select id="paid_by" name="paid_by" class="dot-input">@foreach (\App\Models\TrainingRecord::PAID_BY as $value => $label)<option value="{{ $value }}" @selected(old('paid_by', 'business') === $value)>{{ $label }}</option>@endforeach</select></div>
                </div>
                <div><label for="sponsor">Sponsor or funder, if one pays</label><input id="sponsor" name="sponsor" class="dot-input" maxlength="120" value="{{ old('sponsor') }}" placeholder="Services SETA discretionary grant"></div>
                <div class="rg-form-row">
                    <div><label for="starts_on">Date</label><input id="starts_on" name="starts_on" type="date" class="dot-input"></div>
                    <div><label for="certification_name">Certificate it gives (optional)</label><input id="certification_name" name="certification_name" class="dot-input" maxlength="140" value="{{ old('certification_name', $prefill['certification_name']) }}" placeholder="Match the position requirement's name"></div>
                </div>
                <div><button type="submit" class="dot-btn dot-btn-primary">Book it</button></div>
            </form>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            @if ($isAdmin)
                <div class="dot-card dot-card--pad" id="budgets">
                    <div class="dot-label">Budgets</div>
                    @forelse ($budgets as $b)
                        @php $used = $b['booked'] + $b['spent']; $amount = (float) $b['budget']->amount; @endphp
                        <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                            <div style="min-width:0;">
                                <h4 style="font-size:13.5px;">{{ $b['budget']->department ?: 'The whole business' }}</h4>
                                <div class="m">{{ $b['budget']->starts_on->format('j M Y') }} to {{ $b['budget']->ends_on->format('j M Y') }}</div>
                                <div class="rg-track" style="margin:8px 0 6px;" role="img" aria-label="{{ round($amount > 0 ? $used / $amount * 100 : 0) }}% booked or spent"><i style="width:{{ min(100, $amount > 0 ? round($used / $amount * 100) : 0) }}%;{{ $b['left'] < 0 ? 'background:var(--rg-bad);' : '' }}"></i></div>
                                <dl class="rg-facts" style="margin:0;font-size:13px;">
                                    <dt>Budget</dt><dd class="dot-figure">{{ $b['budget']->currency }} {{ number_format($amount, 2, '.', ' ') }}</dd>
                                    <dt>Spent</dt><dd class="dot-figure">{{ number_format($b['spent'], 2, '.', ' ') }}</dd>
                                    <dt>Booked</dt><dd class="dot-figure">{{ number_format($b['booked'], 2, '.', ' ') }}</dd>
                                    <dt>{{ $b['left'] < 0 ? 'Over by' : 'Left' }}</dt><dd class="dot-figure">{{ number_format(abs($b['left']), 2, '.', ' ') }}</dd>
                                </dl>
                                @if ($b['uncosted'] > 0)<p class="dot-note" style="margin:6px 0 0;">{{ $b['uncosted'] }} {{ Str::plural('course', $b['uncosted']) }} in this period {{ $b['uncosted'] === 1 ? 'has' : 'have' }} no cost recorded, so {{ $b['uncosted'] === 1 ? 'it is' : 'they are' }} not counted.</p>@endif
                                <form method="POST" action="{{ route('training.budgets.destroy', $b['budget']) }}" style="margin:6px 0 0;" data-confirm="Remove this budget? Training records are not touched.">@csrf @method('DELETE')<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Remove</button></form>
                            </div>
                        </div>
                    @empty
                        <p class="dot-note" style="margin:6px 0 0;">No budget set. Only training the business pays counts against a budget; what people or sponsors pay is shown but not counted.</p>
                    @endforelse
                    <details style="margin-top:10px;">
                        <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Set a budget</summary>
                        <form method="POST" action="{{ route('training.budgets.store') }}" style="display:grid;gap:8px;margin-top:8px;">
                            @csrf
                            <div>
                                <label for="b-dept">For</label>
                                <select id="b-dept" name="department" class="dot-input">
                                    <option value="">The whole business</option>
                                    @foreach ($departments as $dept)<option value="{{ $dept }}">{{ $dept }}</option>@endforeach
                                </select>
                            </div>
                            <div class="rg-form-row">
                                <div><label for="b-start">From</label><input id="b-start" name="starts_on" type="date" class="dot-input" required value="{{ now()->startOfYear()->toDateString() }}"></div>
                                <div><label for="b-end">To</label><input id="b-end" name="ends_on" type="date" class="dot-input" required value="{{ now()->endOfYear()->toDateString() }}"></div>
                            </div>
                            <div class="rg-form-row">
                                <div><label for="b-amount">Amount</label><input id="b-amount" name="amount" type="number" step="0.01" min="1" class="dot-input" required></div>
                                <div><label for="b-currency">Currency</label><input id="b-currency" name="currency" class="dot-input" maxlength="3" value="ZAR" required></div>
                            </div>
                            <div><label for="b-note">Note (optional)</label><input id="b-note" name="note" class="dot-input" maxlength="255"></div>
                            <div><button type="submit" class="dot-btn dot-btn-primary">Set it</button></div>
                        </form>
                    </details>
                </div>
            @endif

            <form method="GET" action="{{ route('training.report') }}" class="dot-card dot-card--pad" style="display:grid;gap:8px;">
                <div class="dot-label" style="margin:0;">Training report</div>
                <p class="dot-note" style="margin:0;">Completed training for a period, as CSV: who, which course, by whom, when, hours, cost and who paid. The material a skills-development report is built from.</p>
                <div class="rg-form-row">
                    <div><label for="r-from">From</label><input id="r-from" name="from" type="date" class="dot-input" value="{{ now()->startOfYear()->toDateString() }}"></div>
                    <div><label for="r-to">To</label><input id="r-to" name="to" type="date" class="dot-input" value="{{ now()->toDateString() }}"></div>
                </div>
                <div><button type="submit" class="dot-btn">Download CSV</button></div>
            </form>

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
                    <div class="rg-entry"><div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $record->employee->fullName() }} — {{ $record->title }}</h4><div class="m">{{ $record->completed_on?->format('j M Y') }}@if ($record->hours) · {{ rtrim(rtrim($record->hours, '0'), '.') }}h @endif @if ($record->costLabel()) · {{ $record->costLabel() }}@endif</div></div></div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-app-layout>
