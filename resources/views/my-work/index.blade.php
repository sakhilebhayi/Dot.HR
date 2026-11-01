<x-app-layout>
<div class="page-pad">
    @php
        $left = $checklists->flatMap->tasks->whereNull('completed_at')->count();
        $openRequests = $requests->where('status', '!=', 'closed')->count();
    @endphp
    <h1 class="rg-greet">
        @if ($left > 0)
            <em>{{ $left }} {{ Str::plural('step', $left) }}</em> {{ $employee->team->name }} needs from you.
        @elseif ($reviews->firstWhere('status', 'self'))
            Your review is waiting for <em>your own reflection</em>.
        @elseif ($reviews->firstWhere('status', 'shared'))
            Your review feedback is <em>ready to read</em>.
        @elseif ($shifts->isNotEmpty())
            Your next shift is <em>{{ $shifts->first()->starts_at->isSameDay(\App\Support\Hr\LocalTime::today()) ? 'today' : $shifts->first()->starts_at->format('l') }}</em>, {{ $shifts->first()->window() }}.
        @else
            Nothing is waiting on you at {{ $employee->team->name }}.
        @endif
    </h1>
    <p class="rg-sub">Your own record, your time, your reviews and training, and anything you've asked HR.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            {{-- Time (R-32 Phase 6a): the clock, the next fortnight's shifts, two weeks of hours. --}}
            <div class="dot-card dot-card--pad">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div>
                        <div class="dot-label" style="margin:0;">Your time</div>
                        <div style="font-weight:500;">
                            @if ($openClock) Clocked in since {{ $openClock->clock_in_at->format('H:i') }} @else Not clocked in @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('my-work.clock', $openClock ? 'out' : 'in') }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="dot-btn {{ $openClock ? '' : 'dot-btn-primary' }}">{{ $openClock ? 'Clock out' : 'Clock in' }}</button>
                    </form>
                </div>
                <p class="dot-note" style="margin:8px 0 0;">Only the time is recorded — never where you are.</p>

                @if ($shifts->isNotEmpty())
                    <div class="dot-label" style="margin-top:14px;">Your next shifts</div>
                    @foreach ($shifts as $shift)
                        @php $offered = $mySwaps->first(fn ($sw) => $sw->shift_id === $shift->id && $sw->isOpen()); @endphp
                        <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
                                <div style="min-width:0;"><h4 style="font-size:14px;">{{ $shift->starts_at->format('D j M') }}, {{ $shift->window() }}</h4><div class="m">{{ collect([$shift->title, $shift->location])->filter()->join(', ') }}</div></div>
                                @if ($offered)
                                    <span class="dot-badge dot-badge-accent">Offered, {{ strtolower($offered->statusLabel()) }}</span>
                                @endif
                            </div>
                            @if (! $offered && $shift->starts_at->isFuture())
                                <details style="margin-top:6px;">
                                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;min-height:30px;padding:3px 10px;">Swap or give away</summary>
                                    <form method="POST" action="{{ route('my-work.swaps.offer', $shift) }}" style="display:grid;gap:8px;margin-top:8px;">
                                        @csrf
                                        <div>
                                            <label for="sw-with-{{ $shift->id }}">Offer it to</label>
                                            <select id="sw-with-{{ $shift->id }}" name="with" class="dot-input">
                                                <option value="">Anyone here who is free</option>
                                                <optgroup label="Give it to">
                                                    @foreach ($colleagues as $c)<option value="e:{{ $c->id }}">{{ $c->fullName() }}</option>@endforeach
                                                </optgroup>
                                                @if ($colleagueShifts->isNotEmpty())
                                                    <optgroup label="Swap it for their shift">
                                                        @foreach ($colleagueShifts as $theirs)
                                                            @foreach ($theirs->employees as $c)
                                                                <option value="s:{{ $theirs->id }}:{{ $c->id }}">{{ $c->fullName() }}, {{ $theirs->starts_at->format('D j M') }} {{ $theirs->window() }}</option>
                                                            @endforeach
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            </select>
                                        </div>
                                        <div><label for="sw-note-{{ $shift->id }}">A note (optional)</label><input id="sw-note-{{ $shift->id }}" name="note" class="dot-input" maxlength="255" placeholder="Family wedding that day"></div>
                                        <p class="dot-note" style="margin:0;">Nothing changes until your colleague accepts and a supervisor approves. You stay on the shift until then.</p>
                                        <div><button type="submit" class="dot-btn">Send the offer</button></div>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @endforeach
                @endif

                <div class="dot-label" style="margin-top:14px;">Your weeks</div>
                @foreach ($weeks as $w)
                    <div class="rg-entry" style="align-items:center;">
                        <div>
                            <h4 style="font-size:14px;">Week of {{ $w['start']->format('j M') }}</h4>
                            <div class="m">
                                {{ \App\Models\Timesheet::hours($w['totals']['worked']) }} worked
                                @if ($w['totals']['overtime'] > 0) · {{ \App\Models\Timesheet::hours($w['totals']['overtime']) }} over your ordinary hours @endif
                                @if ($w['sheet']?->status === 'rejected' && $w['sheet']->decision_note) · sent back: {{ $w['sheet']->decision_note }} @endif
                            </div>
                        </div>
                        @if ($w['sheet'] && in_array($w['sheet']->status, ['submitted', 'approved'], true))
                            <span class="dot-badge {{ $w['sheet']->status === 'approved' ? 'dot-badge-ok' : 'dot-badge-accent' }}">{{ \App\Models\Timesheet::STATUSES[$w['sheet']->status] }}</span>
                        @elseif ($w['totals']['worked'] > 0)
                            <form method="POST" action="{{ route('my-work.timesheet.submit') }}" style="margin:0;">
                                @csrf
                                <input type="hidden" name="week_start" value="{{ $w['start']->toDateString() }}">
                                <button type="submit" class="dot-btn">{{ $w['sheet']?->status === 'rejected' ? 'Submit again' : 'Submit timesheet' }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- External work (R-32 Phase 6b): the engagements this person works on as a contractor. --}}
            @foreach ($engagements as $engagement)
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Your engagement — {{ $engagement->title }}</div>
                    <div class="dot-note">{{ $engagement->rate() }} · {{ $engagement->starts_on->format('j M Y') }} to {{ $engagement->ends_on?->format('j M Y') ?? 'open-ended' }}</div>
                    @foreach ($engagement->milestones as $milestone)
                        <div class="rg-entry rg-entry--tools" style="align-items:center;">
                            <div style="min-width:0;"><h4 style="font-size:14px;">{{ $milestone->title }}</h4><div class="m">@if ($milestone->due_on)due {{ $milestone->due_on->format('j M') }}@endif @if ($milestone->status === 'rejected' && $milestone->decision_note) · sent back: {{ $milestone->decision_note }}@endif</div></div>
                            @if (in_array($milestone->status, ['open', 'rejected'], true))
                                <form method="POST" action="{{ route('my-work.milestones.deliver', $milestone) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">Mark as delivered</button></form>
                            @else
                                <span class="dot-badge {{ $milestone->status === 'approved' ? 'dot-badge-ok' : 'dot-badge-accent' }}">{{ \App\Models\EngagementMilestone::STATUSES[$milestone->status] }}</span>
                            @endif
                        </div>
                    @endforeach
                    @if ($engagement->invoices->isNotEmpty())
                        <div class="dot-label" style="margin-top:14px;">Your invoices</div>
                        @foreach ($engagement->invoices as $invoice)
                            <div class="rg-entry"><div><h4 style="font-size:14px;">{{ $invoice->reference }} · {{ $engagement->currency }} {{ number_format((float) $invoice->amount, 2) }}</h4>@if ($invoice->status === 'disputed' && $invoice->note)<div class="m">{{ $invoice->note }}</div>@endif</div><span class="dot-badge {{ $invoice->status === 'paid' ? 'dot-badge-ok' : ($invoice->status === 'disputed' ? 'dot-badge-bad' : 'dot-badge-muted') }}">{{ \App\Models\ContractorInvoice::STATUSES[$invoice->status] }}</span></div>
                        @endforeach
                    @endif
                </div>
            @endforeach

            @foreach ($checklists as $checklist)
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">{{ $checklist->label() }}{{ $checklist->process === 'offboarding' && $checklist->last_day ? ' — your last day is '.$checklist->last_day->format('j M Y') : '' }}</div>
                    @forelse ($checklist->tasks as $task)
                        <div class="rg-task {{ $task->isDone() ? 'rg-task--done' : '' }}">
                            @if ($task->isDone())
                                <span class="rg-tick rg-tick--done" aria-hidden="true">✓</span>
                                <div style="min-width:0;flex:1;">
                                    <div class="rg-task-title">{{ $task->title }}</div>
                                    <div class="rg-owner">{{ $task->kind === 'acknowledge' ? 'You confirmed you read it' : 'Done' }} · {{ $task->completed_at->format('j M Y') }}</div>
                                </div>
                            @elseif ($task->kind === 'acknowledge')
                                <span class="rg-tick" aria-hidden="true"></span>
                                <div style="min-width:0;flex:1;">
                                    <div class="rg-task-title" style="font-weight:500;">{{ $task->title }}</div>
                                    @if ($task->body)<p style="white-space:pre-line;margin:6px 0 8px;" class="dot-note">{{ $task->body }}</p>@endif
                                    <form method="POST" action="{{ route('my-work.tasks.complete', $task) }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                        @csrf
                                        <label style="display:inline-flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="confirm" value="1" required> I have read this</label>
                                        <button type="submit" class="dot-btn">Confirm</button>
                                    </form>
                                </div>
                            @else
                                <form method="POST" action="{{ route('my-work.tasks.complete', $task) }}">
                                    @csrf
                                    <button type="submit" class="rg-tick" aria-label="Mark done: {{ $task->title }}">✓</button>
                                </form>
                                <div style="min-width:0;flex:1;"><div class="rg-task-title">{{ $task->title }}</div></div>
                            @endif
                        </div>
                    @empty
                        <p class="dot-note" style="margin:0;">Nothing for you on this one — HR handles every step.</p>
                    @endforelse
                </div>
            @endforeach

            {{-- Development (R-32 Phase 6b): written reviews and goals — no scores, no ranking. --}}
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Your reviews and goals</div>
                @if ($reviewing > 0)
                    <p style="margin:6px 0 10px;"><a href="{{ route('reviews.index') }}">{{ $reviewing }} {{ Str::plural('review', $reviewing) }} waiting for your feedback</a></p>
                @endif
                @foreach ($reviews as $review)
                    <a href="{{ route('reviews.show', $review) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;"><h4 style="font-size:14px;">{{ $review->cycle->name }}</h4><div class="m">with {{ $review->reviewer->name }}</div></div>
                        <span class="dot-badge {{ in_array($review->status, ['self', 'shared'], true) ? 'dot-badge-accent' : ($review->status === 'acknowledged' ? 'dot-badge-ok' : 'dot-badge-muted') }}">{{ ['self' => 'Your reflection', 'reviewer' => 'With your reviewer', 'shared' => 'Ready to read', 'acknowledged' => 'Complete'][$review->status] }}</span>
                    </a>
                @endforeach
                @foreach ($goals as $goal)
                    <div class="rg-entry rg-entry--tools" style="align-items:center;">
                        <div style="min-width:0;"><h4 style="font-size:14px;{{ $goal->status === 'done' ? 'color:var(--rg-muted);' : '' }}">{{ $goal->title }}</h4><div class="m">{{ \App\Models\Goal::KINDS[$goal->kind] }}@if ($goal->due_on) · by {{ $goal->due_on->format('j M Y') }}@endif</div></div>
                        <form method="POST" action="{{ route('goals.update', $goal) }}" style="margin:0;">
                            @csrf
                            @method('PUT')
                            <label for="goal-{{ $goal->id }}" style="position:absolute;left:-9999px;">Progress on {{ $goal->title }}</label>
                            <select id="goal-{{ $goal->id }}" name="status" class="dot-input" style="width:auto;min-height:32px;padding:4px 8px;" data-autosubmit>
                                @foreach (\App\Models\Goal::STATUSES as $value => $label)<option value="{{ $value }}" @selected($goal->status === $value)>{{ $label }}</option>@endforeach
                            </select>
                            <noscript><button type="submit" class="dot-btn">Save</button></noscript>
                        </form>
                    </div>
                @endforeach
                <details style="margin-top:10px;">
                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;display:inline-flex;">Add a goal</summary>
                    <form method="POST" action="{{ route('my-work.goals.store') }}" class="rg-form-row rg-form-row--3" style="margin-top:10px;align-items:end;">
                        @csrf
                        <div><label for="g-title">Goal</label><input id="g-title" name="title" class="dot-input" maxlength="160" required placeholder="Lead a stocktake on my own"></div>
                        <div><label for="g-kind">For</label><select id="g-kind" name="kind" class="dot-input">@foreach (\App\Models\Goal::KINDS as $value => $label)<option value="{{ $value }}" @selected($value === 'development')>{{ $label }}</option>@endforeach</select></div>
                        <div style="display:flex;gap:6px;align-items:end;"><div style="flex:1;"><label for="g-due">By</label><input id="g-due" name="due_on" type="date" class="dot-input"></div><button type="submit" class="dot-btn">Add</button></div>
                    </form>
                </details>
            </div>

            @include('documents._mine')

            <div class="dot-card dot-card--pad" id="details">
                <div class="dot-label">Your details</div>
                <p class="dot-note" style="margin:0 0 12px;">You keep these up to date. Your job title, pay and dates are kept by HR — raise a request below if one is wrong.</p>
                <form method="POST" action="{{ route('my-work.details') }}" style="display:grid;gap:12px;">
                    @csrf
                    @method('PUT')
                    <div class="rg-form-row">
                        <div><label for="phone">Your phone</label><input id="phone" name="phone" class="dot-input" maxlength="40" value="{{ old('phone', $employee->phone) }}"></div>
                        <div></div>
                    </div>
                    <div class="rg-form-row">
                        <div><label for="ec-name">Emergency contact</label><input id="ec-name" name="emergency_contact_name" class="dot-input" maxlength="120" value="{{ old('emergency_contact_name', $employee->emergency_contact_name) }}" placeholder="Name and how they relate to you"></div>
                        <div><label for="ec-phone">Their phone</label><input id="ec-phone" name="emergency_contact_phone" class="dot-input" maxlength="40" value="{{ old('emergency_contact_phone', $employee->emergency_contact_phone) }}"></div>
                    </div>
                    <div><button type="submit" class="dot-btn dot-btn-primary">Save my details</button></div>
                </form>
                <p class="dot-note" style="margin:12px 0 0;">Want everything {{ $employee->team->name }} holds about you in Dot.HR? <a href="{{ route('my-work.data') }}">Download my data</a> — one file, yours to keep.</p>
            </div>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Your job</div>
                <div style="font-weight:500;">{{ $employee->position?->title ?? 'No position recorded' }}</div>
                <div class="dot-note">{{ ucfirst(str_replace('_', ' ', $employee->employment_type)) }} · {{ ucfirst(str_replace('_', ' ', $employee->status)) }}</div>
                @if ($employee->start_date)<div class="dot-note">Since {{ $employee->start_date->format('j M Y') }}</div>@endif
                <a href="{{ route('leave-requests.create') }}" class="dot-btn" style="margin-top:12px;">Request leave</a>
            </div>

            @if ($certifications->isNotEmpty())
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Your certificates</div>
                    @foreach ($certifications as $cert)
                        <div class="rg-entry">
                            <div><h4 style="font-size:13.5px;">{{ $cert->name }}</h4><div class="m">{{ $cert->expires_on ? ($cert->state() === 'expired' ? 'Expired ' : 'Valid until ').$cert->expires_on->format('j M Y') : 'Does not expire' }}</div></div>
                            @if ($cert->state() !== 'valid')<span class="dot-badge {{ $cert->state() === 'expired' ? 'dot-badge-bad' : 'dot-badge-warn' }}">{{ $cert->state() === 'expired' ? 'renew' : 'soon' }}</span>@endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Training</div>
                @foreach ($training as $record)
                    <div class="rg-entry">
                        <div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $record->title }}</h4><div class="m">@if ($record->status === 'completed' && $record->completed_on)completed {{ $record->completed_on->format('j M Y') }}@elseif ($record->starts_on){{ $record->starts_on->format('j M Y') }}@endif @if ($record->status === 'declined' && $record->decision_note) · {{ $record->decision_note }}@endif</div></div>
                        <span class="dot-badge {{ ['requested' => 'dot-badge-muted', 'planned' => 'dot-badge-accent', 'completed' => 'dot-badge-ok', 'declined' => 'dot-badge-warn'][$record->status] }}">{{ \App\Models\TrainingRecord::STATUSES[$record->status] }}</span>
                    </div>
                @endforeach
                <details style="margin-top:{{ $training->isEmpty() ? '0' : '10px' }};">
                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;display:inline-flex;">Ask for training</summary>
                    <form method="POST" action="{{ route('my-work.training.store') }}" style="display:grid;gap:8px;margin-top:10px;">
                        @csrf
                        <div><label for="t-title">What</label><input id="t-title" name="title" class="dot-input" maxlength="160" required placeholder="First aid level 1"></div>
                        <div><label for="t-provider">Who runs it (if you know)</label><input id="t-provider" name="provider" class="dot-input" maxlength="140"></div>
                        <div><label for="t-kind">Kind</label><select id="t-kind" name="kind" class="dot-input">@foreach (\App\Models\TrainingRecord::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                        <div><label for="t-reason">Why it would help</label><input id="t-reason" name="reason" class="dot-input" maxlength="255"></div>
                        <button type="submit" class="dot-btn">Ask</button>
                    </form>
                </details>
            </div>

            @include('my-work._swaps')

            @include('my-work._hr-matters')

            @include('my-work._leaving')

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Ask HR</div>
                <form method="POST" action="{{ route('my-work.requests.store') }}" style="display:grid;gap:8px;">
                    @csrf
                    <label for="req-category" style="position:absolute;left:-9999px;">About</label>
                    <select id="req-category" name="category" class="dot-input">
                        @foreach (\App\Models\HrRequest::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <label for="req-subject" style="position:absolute;left:-9999px;">Subject</label>
                    <input id="req-subject" name="subject" class="dot-input" maxlength="160" placeholder="In a few words" required>
                    <label for="req-body" style="position:absolute;left:-9999px;">Your question</label>
                    <textarea id="req-body" name="body" class="dot-input" rows="3" maxlength="5000" placeholder="Only HR administrators read this — not your manager." required></textarea>
                    <button type="submit" class="dot-btn">Send to HR</button>
                </form>
                @if ($requests->isNotEmpty())
                    <div style="margin-top:12px;">
                        @foreach ($requests as $hrRequest)
                            <a href="{{ route('my-work.requests.show', $hrRequest) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                                <div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $hrRequest->subject }}</h4><div class="m">{{ $hrRequest->reference() }} · {{ $hrRequest->created_at->format('j M') }}</div></div>
                                <span class="dot-badge {{ $hrRequest->status === 'open' ? 'dot-badge-warn' : ($hrRequest->status === 'answered' ? 'dot-badge-ok' : 'dot-badge-muted') }}">{{ \App\Models\HrRequest::STATUSES[$hrRequest->status] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
</x-app-layout>
