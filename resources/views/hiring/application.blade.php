<x-app-layout>
<div class="page-pad">
    <p class="dot-note" style="margin:0 0 6px;">
        <a href="{{ route('hiring.index') }}">Hiring</a> · <a href="{{ route('hiring.show', $vacancy) }}">{{ $vacancy->title }}</a> · {{ $application->reference() }}
    </p>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div style="min-width:0;">
            <h1 style="margin:0;">{{ $application->user->name }}</h1>
            <p class="rg-sub" style="margin:4px 0 0;">
                <span class="dot-badge dot-badge-accent">{{ $application->stageLabel() }}{{ $application->step ? ': '.$application->step->name : '' }}</span>
                Applied {{ $application->created_at->format('j M Y') }}
                · <a href="mailto:{{ $application->user->email }}">{{ $application->user->email }}</a>
                @if ($match) · <span style="color:var(--rg-accent-ink);">{{ $match }}</span> @endif
            </p>
        </div>
        @if ($canRecruit && $application->isOpen() && $application->stage !== 'offer')
            <form method="POST" action="{{ route('hiring.applications.move', $application) }}" style="display:flex;gap:6px;margin:0;">
                @csrf
                <label for="stage" style="position:absolute;left:-9999px;">Move to</label>
                <select id="stage" name="stage" class="dot-input" style="width:auto;">
                    @foreach (\App\Models\Application::MOVABLE_TO as $stage)
                        @if ($stage !== $application->stage)
                            <option value="{{ $stage }}">{{ \App\Models\Application::STAGES[$stage] }}</option>
                        @endif
                    @endforeach
                </select>
                <button type="submit" class="dot-btn">Move</button>
            </form>
        @endif
    </div>
    @if ($canRecruit && $application->isOpen() && $steps->isNotEmpty())
        <form method="POST" action="{{ route('hiring.applications.step', $application) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:10px 0 0;">
            @csrf
            <label for="step" class="dot-note" style="margin:0;">Step in {{ $application->stageLabel() }}</label>
            <select id="step" name="hiring_step_id" class="dot-input" style="width:auto;" data-autosubmit>
                <option value="">No step</option>
                @foreach ($steps as $step)<option value="{{ $step->id }}" @selected($application->hiring_step_id === $step->id)>{{ $step->name }}</option>@endforeach
            </select>
            <noscript><button type="submit" class="dot-btn">Set</button></noscript>
        </form>
    @endif

    <div style="margin-top:18px;">
        <x-flash />
        @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif
    </div>

    <div class="rg-grid">
        {{-- What the candidate gave this business --}}
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            @if ($application->cover_note || ($vacancy->screening_questions ?? []) !== [])
                <div class="dot-card dot-card--pad">
                    @if ($application->cover_note)
                        <div class="dot-label">Their note</div>
                        <p style="margin:0 0 12px;white-space:pre-line;">{{ $application->cover_note }}</p>
                    @endif
                    @foreach ($vacancy->screening_questions ?? [] as $i => $question)
                        <div class="dot-label">{{ $question }}</div>
                        <p style="margin:0 0 10px;white-space:pre-line;">{{ $application->answers[$i] ?? '—' }}</p>
                    @endforeach
                </div>
            @endif

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Their profile</div>
                @if ($profile)
                    @if ($profile->headline)<div style="font-weight:500;">{{ $profile->headline }}</div>@endif
                    <div class="dot-note">{{ \App\Models\ProfessionalProfile::AVAILABILITY[$profile->availability] ?? '' }}@if ($profile->location) · {{ $profile->location }}@endif</div>
                    @if ($profile->about)<p style="white-space:pre-line;margin:10px 0 0;">{{ $profile->about }}</p>@endif
                    @if ($profile->skills->isNotEmpty())
                        <div class="rg-chips" style="margin-top:12px;">
                            @foreach ($profile->skills as $skill)<span class="rg-chip" style="padding-right:11px;">{{ $skill->name }}</span>@endforeach
                        </div>
                    @endif
                    @foreach (\App\Models\ProfileEntry::KINDS as $kind => $kindLabel)
                        @php $items = $profile->entriesOf($kind); @endphp
                        @if ($items->isNotEmpty())
                            <div class="dot-label" style="margin-top:14px;">{{ $kindLabel }}</div>
                            @foreach ($items as $entry)
                                <div class="rg-entry"><div><h4>{{ $entry->title }}</h4><div class="m">{{ $entry->organisation }}@if ($entry->period()) · {{ $entry->period() }}@endif</div></div></div>
                            @endforeach
                        @endif
                    @endforeach
                @else
                    <p class="dot-note" style="margin:0;">This person has not written a profile yet.</p>
                @endif
                <p class="dot-note" style="margin:12px 0 0;">Shared with your business because they applied. Matching uses only the skills they listed — nothing about who they are.</p>
            </div>

            {{-- Interviews --}}
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Interviews</div>
                @forelse ($application->interviews as $interview)
                    <div class="rg-entry">
                        <div>
                            <h4>{{ $interview->starts_at->format('D j M Y, H:i') }} · {{ $interview->duration_minutes }} min · {{ $interview->modeLabel() }}</h4>
                            <div class="m">{{ $interview->where }} @if ($interview->status !== 'scheduled') · {{ ucfirst($interview->status) }} @endif</div>
                            @if ($interview->recommendation)
                                <p class="dot-note" style="margin:6px 0 0;"><strong>{{ \App\Models\Interview::RECOMMENDATIONS[$interview->recommendation] }}</strong>@if ($interview->feedback) — {{ $interview->feedback }}@endif</p>
                            @endif
                        </div>
                        <div style="display:flex;gap:6px;align-items:flex-start;">
                            @if ($interview->status !== 'cancelled' && ! $interview->recommendation)
                                <details>
                                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Feedback</summary>
                                    <form method="POST" action="{{ route('hiring.applications.interviews.feedback', [$application, $interview->id]) }}" style="display:grid;gap:6px;margin-top:6px;min-width:240px;">
                                        @csrf
                                        <select name="recommendation" class="dot-input">
                                            @foreach (\App\Models\Interview::RECOMMENDATIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                        <textarea name="feedback" class="dot-input" rows="3" placeholder="What you saw, against the role's needs"></textarea>
                                        <button type="submit" class="dot-btn">Save feedback</button>
                                    </form>
                                </details>
                            @endif
                            @if ($canRecruit && $interview->status === 'scheduled')
                                <form method="POST" action="{{ route('hiring.applications.interviews.cancel', [$application, $interview->id]) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="dot-btn dot-btn-danger">Cancel</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:0;">None yet.</p>
                @endforelse

                @if ($canRecruit && $application->isOpen())
                    <details style="margin-top:12px;">
                        <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Schedule an interview</summary>
                        <form method="POST" action="{{ route('hiring.applications.interviews.store', $application) }}" style="display:grid;gap:10px;margin-top:10px;">
                            @csrf
                            <div class="rg-form-row">
                                <div><label for="starts_at">When</label><input id="starts_at" name="starts_at" type="datetime-local" class="dot-input" required></div>
                                <div><label for="duration_minutes">Minutes</label><input id="duration_minutes" name="duration_minutes" type="number" min="10" max="480" value="45" class="dot-input" required></div>
                            </div>
                            <div class="rg-form-row">
                                <div>
                                    <label for="mode">How</label>
                                    <select id="mode" name="mode" class="dot-input">@foreach (\App\Models\Interview::MODES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                </div>
                                <div><label for="where">Where — address, number or link</label><input id="where" name="where" class="dot-input" maxlength="255"></div>
                            </div>
                            <div><label for="candidate_note">A note for the candidate</label><textarea id="candidate_note" name="candidate_note" class="dot-input" rows="2" maxlength="2000" placeholder="Bring your ID and a copy of your certificate."></textarea></div>
                            <div><button type="submit" class="dot-btn dot-btn-primary">Schedule and tell them</button></div>
                        </form>
                    </details>
                @endif
            </div>

            {{-- Offer --}}
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Offer</div>
                @forelse ($application->offers as $offer)
                    <div class="rg-entry">
                        <div>
                            <h4>{{ $offer->job_title }} · {{ $offer->payLabel() }}</h4>
                            <div class="m">Starts {{ $offer->starts_on->format('j M Y') }} · {{ \App\Models\Offer::STATUSES[$offer->status] }}</div>
                        </div>
                        @if ($canRecruit)
                            <div style="display:flex;gap:6px;align-items:flex-start;">
                                @if ($offer->status === 'draft')
                                    <form method="POST" action="{{ route('hiring.applications.offers.send', [$application, $offer->id]) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-primary">Send</button></form>
                                @endif
                                @if (in_array($offer->status, ['draft', 'sent'], true))
                                    <form method="POST" action="{{ route('hiring.applications.offers.withdraw', [$application, $offer->id]) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-danger">Withdraw</button></form>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="dot-note" style="margin:0;">No offer yet.</p>
                @endforelse

                @if ($canRecruit && $application->isOpen() && ! $application->offers->whereIn('status', ['draft', 'sent'])->count())
                    <details style="margin-top:12px;">
                        <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Make an offer</summary>
                        <form method="POST" action="{{ route('hiring.applications.offers.store', $application) }}" style="display:grid;gap:10px;margin-top:10px;">
                            @csrf
                            <div class="rg-form-row">
                                <div><label for="job_title">Job title</label><input id="job_title" name="job_title" class="dot-input" required maxlength="140" value="{{ $vacancy->title }}"></div>
                                <div><label for="offer_starts_on">Start date</label><input id="offer_starts_on" name="starts_on" type="date" class="dot-input" required value="{{ $vacancy->starts_on?->format('Y-m-d') }}"></div>
                            </div>
                            <div class="rg-form-row">
                                <div>
                                    <label for="offer_pay_basis">Pay basis</label>
                                    <select id="offer_pay_basis" name="pay_basis" class="dot-input">@foreach (\App\Models\Vacancy::PAY_BASES as $value => $label)<option value="{{ $value }}" @selected($vacancy->pay_basis === $value)>{{ $label }}</option>@endforeach</select>
                                </div>
                                <div><label for="pay_amount">Amount</label><input id="pay_amount" name="pay_amount" type="number" min="0" step="0.01" class="dot-input" value="{{ $vacancy->pay_min }}"></div>
                            </div>
                            <input type="hidden" name="currency" value="{{ $vacancy->currency }}">
                            <div><label for="terms">Terms the candidate should read</label><textarea id="terms" name="terms" class="dot-input" rows="4" maxlength="6000" placeholder="Probation, leave, benefits, the hours."></textarea></div>
                            <label style="display:inline-flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="send" value="1" checked> Send it now</label>
                            <div><button type="submit" class="dot-btn dot-btn-primary">Save offer</button></div>
                            <p class="dot-note" style="margin:0;">When the candidate accepts, their employee record is created in your business — once.</p>
                        </form>
                    </details>
                @endif
            </div>
        </div>

        {{-- Private to the business --}}
        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Notes — only your business sees these</div>
                <form method="POST" action="{{ route('hiring.applications.note', $application) }}" style="display:grid;gap:6px;margin-bottom:10px;">
                    @csrf
                    <label for="note-body" style="position:absolute;left:-9999px;">Add a note</label>
                    <textarea id="note-body" name="body" class="dot-input" rows="3" maxlength="4000" placeholder="Against the role's needs, not the person"></textarea>
                    <button type="submit" class="dot-btn">Add note</button>
                </form>
                @foreach ($application->notes as $note)
                    <div style="border-top:1px solid var(--rg-rule);padding:8px 0;">
                        <div class="dot-note">{{ $note->author?->name ?? 'Someone' }} · {{ $note->created_at->diffForHumans() }}</div>
                        <div style="white-space:pre-line;">{{ $note->body }}</div>
                    </div>
                @endforeach
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">History</div>
                @foreach ($application->events as $event)
                    <div style="border-top:1px solid var(--rg-rule);padding:7px 0;font-size:13px;">
                        {{ $event->describe() }}
                        <div class="dot-note">{{ $event->actor?->name ?? 'System' }} · {{ $event->created_at->format('j M, H:i') }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
</x-app-layout>
