<x-app-layout>
<div class="page-pad">
    @php
        $person = $case->employee;
        $first = $person?->first_name ?? 'them';
        $steps = match ($case->type) {
            'incident' => ['open' => 'Recorded', 'investigating' => 'Investigating', 'decided' => 'Decided', 'closed' => 'Closed'],
            'grievance' => ['open' => 'Lodged', 'investigating' => 'Investigating', 'hearing' => 'Meeting', 'decided' => 'Decided', 'closed' => 'Closed'],
            default => ['open' => 'Opened', 'investigating' => 'Investigating', 'hearing' => 'Hearing', 'decided' => 'Decided', 'closed' => 'Closed'],
        };
        if ($case->appealed_at) {
            $steps = array_slice($steps, 0, -1, true) + ['appealed' => 'Appeal'] + ['closed' => 'Closed'];
        }
        $keys = array_keys($steps);
        $now = array_search($case->status === 'decided' && $case->appeal_decided_at ? 'appealed' : $case->status, $keys, true);
        $active = in_array($case->status, ['open', 'investigating', 'hearing'], true);
        $summaryLabel = ['grievance' => 'What was raised', 'disciplinary' => 'What is alleged', 'incident' => 'What happened'][$case->type];
        $due = $case->externalReportDue();
    @endphp

    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('cases.index') }}">Cases</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">{{ $case->title }}</h1>
    <p class="rg-sub" style="margin:0 0 12px;">
        {{ $case->reference() }}, {{ strtolower($case->typeLabel()) }}{{ $person ? ' about '.$person->fullName() : '' }}, opened {{ $case->created_at->format('j M Y') }}{{ $case->source === 'employee' ? ' from My work' : '' }}.
    </p>

    @if ($case->status === 'withdrawn')
        <p style="margin:0 0 16px;"><span class="dot-badge dot-badge-muted">Withdrawn {{ $case->closed_at?->format('j M Y') }}</span></p>
    @else
        <ol class="rg-steps" aria-label="Where this case is" style="margin-bottom:18px;">
            @foreach ($steps as $key => $label)
                @php $i = array_search($key, $keys, true); @endphp
                <li class="{{ $i === $now ? 'is-now' : ($now !== false && $i < $now ? 'is-done' : '') }}" @if ($i === $now) aria-current="step" @endif>{{ $label }}</li>
            @endforeach
        </ol>
    @endif

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif
    @if ($due)
        <div class="dot-flash dot-flash-err" role="alert">Report this work injury to the Compensation Fund by {{ $due->format('j F Y') }}{{ $due->isPast() ? ', which has passed' : '' }}. Record the date below once it is done.</div>
    @endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">{{ $summaryLabel }}</div>
                <p style="margin:6px 0 0;white-space:pre-line;max-width:68ch;">{{ $case->summary }}</p>
                @if ($case->isIncapacity())
                    <p class="dot-note" style="margin:10px 0 0;">An incapacity matter: before any sanction, record the guidance and training given and the time allowed to improve (Schedule 8, items 8 and 9).</p>
                @endif
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">The record</div>
                @if ($case->entries->isEmpty())
                    <p class="dot-note" style="margin:6px 0 0;">Nothing written yet. Notes stay internal unless you share them with {{ $first }}.</p>
                @else
                    <ol class="rg-timeline" style="margin-top:6px;">
                        @foreach ($case->entries as $entry)
                            <li>
                                <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('j M Y') }}<br>{{ $entry->created_at->format('H:i') }}</time>
                                <div class="{{ $entry->by_employee ? 'from-them' : '' }}">
                                    <div class="head">
                                        <strong>{{ $entry->kindLabel() }}</strong>
                                        <span>{{ $entry->by_employee ? 'from '.($person?->fullName() ?? 'the person') : ($entry->author?->name ?? 'HR') }}</span>
                                        @unless ($entry->by_employee)
                                            <span class="dot-badge {{ $entry->shared ? 'dot-badge-accent' : 'dot-badge-muted' }}">{{ $entry->shared ? 'Shared with '.$first : 'Internal' }}</span>
                                        @endunless
                                        @if ($entry->received_at)
                                            <span class="dot-badge dot-badge-ok">Received {{ $entry->received_at->format('j M, H:i') }}</span>
                                        @elseif ($entry->kind === 'notice')
                                            <span class="dot-badge dot-badge-warn">Not yet confirmed</span>
                                        @endif
                                    </div>
                                    <p class="body">{{ $entry->body }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($case->isOpen())
                    <form method="POST" action="{{ route('cases.entries.store', $case) }}" style="display:grid;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid var(--rg-rule);">
                        @csrf
                        <div class="rg-form-row" style="grid-template-columns:200px minmax(0,1fr);">
                            <div>
                                <label for="e-kind">Add</label>
                                <select id="e-kind" name="kind" class="dot-input">
                                    @foreach (\App\Models\HrCaseEntry::WRITABLE as $kind)
                                        <option value="{{ $kind }}">{{ \App\Models\HrCaseEntry::KINDS[$kind] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="e-body">What to record</label>
                                <textarea id="e-body" name="body" class="dot-input" rows="3" maxlength="10000" required placeholder="A witness statement, what was said in a meeting, a step taken"></textarea>
                            </div>
                        </div>
                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                            @if ($person)
                                <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="share" value="1"> Share with {{ $person->fullName() }}{{ $person->user_id ? '' : ' (no account linked yet)' }}</label>
                            @endif
                            <button type="submit" class="dot-btn dot-btn-primary">Add to the record</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">The case</div>
                <dl class="rg-facts">
                    <dt>Status</dt><dd>{{ $case->statusLabel() }}</dd>
                    <dt>Kind</dt><dd>{{ $case->categoryLabel() }}</dd>
                    @if ($case->respondent)<dt>About</dt><dd>{{ $case->respondent->fullName() }}</dd>@endif
                    @if ($case->occurred_on)<dt>Happened</dt><dd>{{ $case->occurred_on->format('j M Y') }}</dd>@endif
                    <dt>Handler</dt><dd>{{ $case->handler?->name ?? 'Nobody yet' }}</dd>
                    <dt>Who can open it</dt><dd>{{ $case->restricted ? 'The handler and the owner only' : 'HR administrators and the handler' }}</dd>
                    @if ($case->hearing_at)
                        <dt>{{ $case->type === 'grievance' ? 'Meeting' : 'Hearing' }}</dt>
                        <dd>{{ $case->hearing_at->format('D j M Y, H:i') }}, {{ $case->hearing_place }}{{ $case->hearing_chair ? ', chaired by '.$case->hearing_chair : '' }}</dd>
                    @endif
                    @if ($case->outcome)
                        <dt>Outcome</dt>
                        <dd>{{ $case->outcomeLabel() }}@if ($case->warning_expires_on), counts until {{ $case->warning_expires_on->format('j M Y') }}@endif</dd>
                    @endif
                    @if ($case->appeal_outcome)<dt>Appeal</dt><dd>{{ \App\Models\HrCase::APPEAL_OUTCOMES[$case->appeal_outcome] }}</dd>@elseif ($case->appealed_at)<dt>Appeal</dt><dd>Lodged {{ $case->appealed_at->format('j M Y') }}, waiting</dd>@endif
                    @if ($case->reported_externally_on)<dt>Reported</dt><dd>{{ $case->reported_externally_on->format('j M Y') }}</dd>@endif
                </dl>
            </div>

            @if ($case->isOpen())
                <div class="dot-card dot-card--pad" style="display:grid;gap:10px;">
                    <div class="dot-label" style="margin:0;">Next step</div>

                    @if ($case->status === 'open')
                        <form method="POST" action="{{ route('cases.investigate', $case) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">Start the investigation</button></form>
                    @endif

                    @if ($active && $case->type !== 'incident')
                        <details @if ($case->status === 'investigating') open @endif>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">{{ $case->hearing_at ? 'Move the' : 'Set a' }} {{ $case->type === 'grievance' ? 'meeting' : 'hearing' }}</summary>
                            <form method="POST" action="{{ route('cases.hearing', $case) }}" style="display:grid;gap:8px;margin-top:8px;">
                                @csrf
                                <div><label for="h-at">When</label><input id="h-at" name="hearing_at" type="datetime-local" class="dot-input" required min="{{ now()->format('Y-m-d\TH:i') }}"></div>
                                <div><label for="h-place">Where</label><input id="h-place" name="hearing_place" class="dot-input" maxlength="160" required placeholder="Boardroom, or a video link"></div>
                                <div><label for="h-chair">Chaired by (optional)</label><input id="h-chair" name="hearing_chair" class="dot-input" maxlength="120" placeholder="Someone not involved in the matter"></div>
                                <div><label for="h-points">{{ $case->type === 'grievance' ? 'What you will discuss' : 'The allegations, in plain words' }}</label><textarea id="h-points" name="points" class="dot-input" rows="4" maxlength="5000" required></textarea></div>
                                <p class="dot-note" style="margin:0;">{{ $first }} receives a notice with the date, the {{ $case->type === 'grievance' ? 'points' : 'allegations' }}, and their right to bring a fellow employee or a shop steward.</p>
                                <div><button type="submit" class="dot-btn dot-btn-primary">Send the notice</button></div>
                            </form>
                        </details>
                    @endif

                    @if ($active)
                        <details @if ($case->status === 'hearing' || ($case->type === 'incident' && $case->status === 'investigating')) open @endif>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Record the outcome</summary>
                            <form method="POST" action="{{ route('cases.decide', $case) }}" style="display:grid;gap:8px;margin-top:8px;">
                                @csrf
                                <div>
                                    <label for="d-outcome">Outcome</label>
                                    <select id="d-outcome" name="outcome" class="dot-input" required>
                                        @foreach (\App\Models\HrCase::OUTCOMES[$case->type] as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div><label for="d-reasons">The reasons</label><textarea id="d-reasons" name="reasons" class="dot-input" rows="4" maxlength="10000" required placeholder="What was found, and why this outcome fits it"></textarea></div>
                                @if ($case->type === 'disciplinary')
                                    <div>
                                        <label for="d-months">A warning counts for (months)</label>
                                        <input id="d-months" name="warning_months" type="number" min="1" max="24" class="dot-input" placeholder="Default: verbal {{ config('hr.warning_months.verbal_warning') }}, written {{ config('hr.warning_months.written_warning') }}, final {{ config('hr.warning_months.final_warning') }}">
                                    </div>
                                    <p class="dot-note" style="margin:0;">A dismissal outcome tells {{ $first }} they may refer a dispute to the CCMA or a bargaining council within 30 days.</p>
                                @endif
                                @if ($person)
                                    <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="share" value="1" checked> Share the outcome and reasons with {{ $first }}</label>
                                @endif
                                <div><button type="submit" class="dot-btn dot-btn-primary">Record the outcome</button></div>
                            </form>
                        </details>
                    @endif

                    @if ($case->status === 'decided' && $case->type !== 'incident' && ! $case->appealed_at)
                        <details>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Record an appeal</summary>
                            <form method="POST" action="{{ route('cases.appeal', $case) }}" style="display:grid;gap:8px;margin-top:8px;">
                                @csrf
                                <div><label for="a-grounds">Their grounds of appeal</label><textarea id="a-grounds" name="grounds" class="dot-input" rows="3" maxlength="5000" required></textarea></div>
                                <div><button type="submit" class="dot-btn">Record the appeal</button></div>
                            </form>
                        </details>
                    @endif

                    @if ($case->status === 'appealed')
                        <form method="POST" action="{{ route('cases.appeal-decision', $case) }}" style="display:grid;gap:8px;">
                            @csrf
                            <div>
                                <label for="ad-outcome">The appeal was</label>
                                <select id="ad-outcome" name="appeal_outcome" class="dot-input" required>
                                    @foreach (\App\Models\HrCase::APPEAL_OUTCOMES as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div><label for="ad-reasons">The reasons</label><textarea id="ad-reasons" name="reasons" class="dot-input" rows="3" maxlength="10000" required></textarea></div>
                            <p class="dot-note" style="margin:0;">Best decided by someone senior to whoever made the first decision. {{ $first }} is told.</p>
                            <div><button type="submit" class="dot-btn dot-btn-primary">Record the appeal decision</button></div>
                        </form>
                    @endif

                    @if ($case->status === 'decided')
                        <form method="POST" action="{{ route('cases.close', $case) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">Close the case</button></form>
                    @endif

                    @if ($case->type === 'incident' && ! $case->reported_externally_on)
                        <details @if ($due) open @endif>
                            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Record that it was reported</summary>
                            <form method="POST" action="{{ route('cases.reported', $case) }}" style="display:flex;gap:6px;align-items:end;margin-top:8px;flex-wrap:wrap;">
                                @csrf
                                <div><label for="r-on">Reported on</label><input id="r-on" name="reported_on" type="date" class="dot-input" max="{{ now()->toDateString() }}" required></div>
                                <button type="submit" class="dot-btn">Save</button>
                            </form>
                        </details>
                    @endif

                    @if ($active)
                        <details>
                            <summary class="dot-btn dot-btn-danger" style="list-style:none;">Withdraw the case</summary>
                            <form method="POST" action="{{ route('cases.withdraw', $case) }}" style="display:grid;gap:8px;margin-top:8px;" data-confirm="Withdraw {{ $case->reference() }}? It stays on record as withdrawn.">
                                @csrf
                                <div><label for="w-reason">Why</label><input id="w-reason" name="reason" class="dot-input" maxlength="1000" required placeholder="Resolved informally, raised in error"></div>
                                <div><button type="submit" class="dot-btn dot-btn-danger">Withdraw</button></div>
                            </form>
                        </details>
                    @endif
                </div>
            @endif

            @if ($admin)
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Handler and access</div>
                    <form method="POST" action="{{ route('cases.assign', $case) }}" style="display:grid;gap:8px;margin-top:6px;">
                        @csrf
                        <label for="as-handler" style="position:absolute;left:-9999px;">Handler</label>
                        <select id="as-handler" name="handler_user_id" class="dot-input">
                            @foreach ($handlers as $handler)
                                <option value="{{ $handler->id }}" @selected($case->handler_user_id === $handler->id)>{{ $handler->name }}</option>
                            @endforeach
                        </select>
                        <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="restricted" value="1" @checked($case->restricted)> Restricted to the handler and the owner</label>
                        <div><button type="submit" class="dot-btn">Save</button></div>
                    </form>
                </div>
            @endif

            @if ($history->isNotEmpty())
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Other cases about {{ $person?->fullName() }}</div>
                    @foreach ($history as $other)
                        <a href="{{ route('cases.show', $other) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;">
                                <h4 style="font-size:13.5px;">{{ $other->reference() }} {{ $other->title }}</h4>
                                <div class="m">{{ $other->outcomeLabel() ?? $other->statusLabel() }}</div>
                            </div>
                            @if ($activeWarnings->contains('id', $other->id))
                                <span class="dot-badge dot-badge-warn">Warning until {{ $other->warning_expires_on?->format('j M Y') }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
</x-app-layout>
