<x-app-layout>
<div class="page-pad">
    @php
        $left = $checklists->flatMap->tasks->whereNull('completed_at')->count();
        $openRequests = $requests->where('status', '!=', 'closed')->count();
    @endphp
    <h1 class="rg-greet">
        @if ($left > 0)
            <em>{{ $left }} {{ Str::plural('step', $left) }}</em> {{ $employee->team->name }} needs from you.
        @elseif ($shifts->isNotEmpty())
            Your next shift is <em>{{ $shifts->first()->starts_at->isSameDay(\App\Support\Hr\LocalTime::today()) ? 'today' : $shifts->first()->starts_at->format('l') }}</em>, {{ $shifts->first()->window() }}.
        @else
            Nothing is waiting on you at {{ $employee->team->name }}.
        @endif
    </h1>
    <p class="rg-sub">Your own record, your steps, and anything you've asked HR.</p>

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
                        <div class="rg-entry"><div><h4 style="font-size:14px;">{{ $shift->starts_at->format('D j M') }} · {{ $shift->window() }}</h4><div class="m">{{ $shift->title }}@if ($shift->location) · {{ $shift->location }}@endif</div></div></div>
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
