<x-app-layout>
<div class="page-pad">
    @php
        $person = $plan->employee;
        $next = $plan->nextCheckIn();
        $steps = $plan->status === 'cancelled' ? ['draft' => 'Drafted', 'cancelled' => 'Cancelled'] : ['draft' => 'Drafted', 'active' => 'Shared and running', 'completed' => 'Completed'];
        $keys = array_keys($steps);
        $now = array_search($plan->status, $keys, true);
        $daysLeft = (int) now()->startOfDay()->diffInDays($plan->ends_on, false);
    @endphp

    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('plans.index') }}">Improvement plans</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">{{ $person->fullName() }}: <em>{{ $plan->title }}</em></h1>
    <p class="rg-sub" style="margin:0 0 12px;">{{ $plan->reference() }}, {{ $plan->starts_on->format('j M') }} to {{ $plan->ends_on->format('j M Y') }}, {{ $plan->periodDays() }} days{{ $plan->extensions ? ', extended '.$plan->extensions.' '.Str::plural('time', $plan->extensions) : '' }}.</p>
    <ol class="rg-steps" aria-label="Where this plan is" style="margin-bottom:18px;">
        @foreach ($steps as $key => $label)
            @php $i = array_search($key, $keys, true); @endphp
            <li class="{{ $i === $now ? 'is-now' : ($i < $now ? 'is-done' : '') }}" @if ($i === $now) aria-current="step" @endif>{{ $label }}</li>
        @endforeach
    </ol>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif
    @if ($plan->status === 'completed' && $plan->outcome === 'not_met')
        <div class="dot-flash dot-flash-err" role="alert">
            The standard is still not met. A plan is not a sanction: any further step goes through an incapacity enquiry, where {{ $person->first_name }} can answer.
            @if ($plan->case) Continue in <a href="{{ route('cases.show', $plan->case) }}">{{ $plan->case->reference() }}</a>. @else <a href="{{ route('cases.create', ['type' => 'disciplinary', 'category' => 'performance', 'employee' => $person->id]) }}">Open an incapacity case</a>. @endif
        </div>
    @endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad" style="display:grid;gap:14px;">
                <div><div class="dot-label">What is below standard</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $plan->concern }}</p></div>
                <div><div class="dot-label">What meeting the standard looks like</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $plan->standard }}</p></div>
                <div><div class="dot-label">How we will help</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $plan->support }}</p></div>
                @if ($plan->employee_response)
                    <div class="rg-timeline"><div class="from-them"><div class="dot-label">{{ $person->first_name }}’s response</div><p style="margin:4px 0 0;white-space:pre-line;">{{ $plan->employee_response }}</p></div></div>
                @endif
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Objectives</div>
                @foreach ($plan->objectives as $objective)
                    <div class="rg-entry">
                        <div style="min-width:0;">
                            <h4>{{ $objective->title }}</h4>
                            <div class="m">Measure: {{ $objective->measure }}{{ $objective->due_on ? ', by '.$objective->due_on->format('j M Y') : '' }}</div>
                        </div>
                        <span class="dot-badge {{ ['open' => 'dot-badge-muted', 'met' => 'dot-badge-ok', 'not_met' => 'dot-badge-warn'][$objective->status] }}">{{ \App\Models\ImprovementPlanObjective::STATUSES[$objective->status] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Check-ins, shared with {{ $person->first_name }}</div>
                @if ($plan->checkIns->isEmpty())
                    <p class="dot-note" style="margin:6px 0 0;">{{ $plan->status === 'active' ? 'None yet. The first is due by '.$next?->format('j M Y').'.' : 'Check-ins start once the plan is shared.' }}</p>
                @else
                    <ol class="rg-timeline" style="margin-top:6px;">
                        @foreach ($plan->checkIns as $checkIn)
                            <li>
                                <time datetime="{{ $checkIn->held_on->toDateString() }}">{{ $checkIn->held_on->format('j M Y') }}</time>
                                <div>
                                    <div class="head"><strong>Check-in</strong><span>{{ $checkIn->author?->name ?? 'HR' }}</span></div>
                                    <p class="body">{{ $checkIn->notes }}</p>
                                    @if ($checkIn->employee_comment)
                                        <div class="from-them" style="margin-top:8px;">
                                            <div class="head"><strong>{{ $person->first_name }} commented</strong><span>{{ $checkIn->commented_at?->format('j M, H:i') }}</span></div>
                                            <p class="body" style="margin-top:4px;">{{ $checkIn->employee_comment }}</p>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($plan->status === 'active')
                    <form method="POST" action="{{ route('plans.check-ins.store', $plan) }}" style="display:grid;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--rg-rule);">
                        @csrf
                        <div class="rg-form-row rg-form-row--lead">
                            <div><label for="ci-on">Held on</label><input id="ci-on" name="held_on" type="date" class="dot-input" required value="{{ now()->toDateString() }}" min="{{ $plan->starts_on->toDateString() }}" max="{{ now()->toDateString() }}"></div>
                            <div><label for="ci-notes">What you discussed, and what happens next</label><textarea id="ci-notes" name="notes" class="dot-input" rows="3" minlength="10" maxlength="10000" required></textarea></div>
                        </div>
                        <fieldset style="border:0;margin:0;padding:0;display:grid;gap:6px;">
                            <legend class="dot-label" style="margin-bottom:4px;">Where each objective stands</legend>
                            @foreach ($plan->objectives as $objective)
                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between;">
                                    <span style="min-width:0;">{{ $objective->title }}</span>
                                    <span class="rg-checks">
                                        @foreach (\App\Models\ImprovementPlanObjective::STATUSES as $value => $label)
                                            <label><input type="radio" name="objective[{{ $objective->id }}]" value="{{ $value }}" @checked($objective->status === $value)> {{ $label }}</label>
                                        @endforeach
                                    </span>
                                </div>
                            @endforeach
                        </fieldset>
                        <div><button type="submit" class="dot-btn dot-btn-primary">Record and share the check-in</button></div>
                    </form>
                @endif
            </div>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">The plan</div>
                <dl class="rg-facts">
                    <dt>Status</dt><dd>{{ $plan->status === 'completed' ? \App\Models\ImprovementPlan::OUTCOMES[$plan->outcome] : $plan->statusLabel() }}</dd>
                    @if ($plan->status === 'active')
                        <dt>Time left</dt><dd>{{ $daysLeft >= 0 ? $daysLeft.' '.Str::plural('day', $daysLeft) : 'Ended '.abs($daysLeft).' days ago, decide the outcome' }}</dd>
                        <dt>Next check-in</dt><dd>{{ $next?->format('j M Y') }}{{ $next?->isPast() ? ', overdue' : '' }}</dd>
                    @endif
                    <dt>Check-ins</dt><dd>Every {{ $plan->check_in_days }} days, {{ $plan->checkIns->count() }} held</dd>
                    <dt>Supported by</dt><dd>{{ $plan->manager?->name ?? 'HR' }}</dd>
                    <dt>Read by {{ $person->first_name }}</dt><dd>{{ $plan->acknowledged_at ? $plan->acknowledged_at->format('j M Y') : ($plan->shared_at ? 'Not yet' : 'Not shared') }}</dd>
                    @if ($plan->case)<dt>Case</dt><dd><a href="{{ route('cases.show', $plan->case) }}">{{ $plan->case->reference() }}</a></dd>@endif
                </dl>
                @if ($plan->outcome_notes)
                    <p class="dot-note" style="margin:10px 0 0;white-space:pre-line;">{{ $plan->outcome_notes }}</p>
                @endif
            </div>

            @if ($plan->isOpen())
                <div class="dot-card dot-card--pad" style="display:grid;gap:10px;">
                    <div class="dot-label" style="margin:0;">Next step</div>
                    @if ($plan->status === 'draft')
                        <a href="{{ route('plans.edit', $plan) }}" class="dot-btn">Edit the draft</a>
                        <form method="POST" action="{{ route('plans.share', $plan) }}" style="margin:0;" data-confirm="Share {{ $plan->reference() }} with {{ $person->fullName() }}? It starts now and they are told.">
                            @csrf
                            <button type="submit" class="dot-btn dot-btn-primary">Share with {{ $person->first_name }}</button>
                        </form>
                        <p class="dot-note" style="margin:0;">Share it in a conversation, not only on screen: go through it together.</p>
                    @else
                        <details @if ($daysLeft < 0) open @endif>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Record the outcome</summary>
                            <form method="POST" action="{{ route('plans.complete', $plan) }}" style="display:grid;gap:8px;margin-top:8px;">
                                @csrf
                                <div>
                                    <label for="c-outcome">Outcome</label>
                                    <select id="c-outcome" name="outcome" class="dot-input" required>
                                        @foreach (\App\Models\ImprovementPlan::OUTCOMES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                                <div><label for="c-notes">Why</label><textarea id="c-notes" name="notes" class="dot-input" rows="3" minlength="10" maxlength="10000" required></textarea></div>
                                <div><button type="submit" class="dot-btn dot-btn-primary">Record the outcome</button></div>
                            </form>
                        </details>
                        <details>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Give more time</summary>
                            <form method="POST" action="{{ route('plans.extend', $plan) }}" style="display:grid;gap:8px;margin-top:8px;">
                                @csrf
                                <div><label for="x-until">New end date</label><input id="x-until" name="ends_on" type="date" class="dot-input" required min="{{ $plan->ends_on->copy()->addDay()->toDateString() }}"></div>
                                <div><label for="x-reason">Why</label><input id="x-reason" name="reason" class="dot-input" maxlength="1000" required placeholder="Real progress; the training date moved"></div>
                                <div><button type="submit" class="dot-btn">Extend</button></div>
                            </form>
                        </details>
                    @endif
                    <details>
                        <summary class="dot-btn dot-btn-danger" style="list-style:none;">Cancel the plan</summary>
                        <form method="POST" action="{{ route('plans.cancel', $plan) }}" style="display:grid;gap:8px;margin-top:8px;">
                            @csrf
                            <div><label for="k-reason">Why</label><input id="k-reason" name="reason" class="dot-input" maxlength="1000" required></div>
                            <div><button type="submit" class="dot-btn dot-btn-danger">Cancel it</button></div>
                        </form>
                    </details>
                </div>
            @endif
        </div>
    </div>
</div>
</x-app-layout>
