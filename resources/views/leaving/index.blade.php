<x-app-layout>
<div class="page-pad">
    @php $upcoming = $leaving->filter(fn ($r) => ! $r->leavingOn()->isPast()); @endphp
    <h1 class="rg-greet">
        @if ($waiting->isNotEmpty())
            <em>{{ $waiting->count() }} {{ Str::plural('resignation', $waiting->count()) }}</em> {{ $waiting->count() === 1 ? 'needs' : 'need' }} you.
        @elseif ($upcoming->isNotEmpty())
            <em>{{ $upcoming->count() }} {{ Str::plural('person', $upcoming->count()) }}</em> {{ $upcoming->count() === 1 ? 'is' : 'are' }} working their notice.
        @else
            Nobody is leaving right now.
        @endif
    </h1>
    <p class="rg-sub">Resignations, exit interviews and certificates of service. The offboarding checklist carries the rest: handover, equipment, access and final pay.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Waiting for you</div>
                @forelse ($waiting as $r)
                    @php $short = $r->status === 'given' && $r->noticeDays() < $r->minimum_notice_days; @endphp
                    <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                        <div style="min-width:0;">
                            <h4>{{ $r->employee->fullName() }} {{ $r->status === 'given' ? 'resigned' : 'asks to withdraw their resignation' }}</h4>
                            <div class="m">
                                {{ $r->reference() }}, given {{ $r->given_at->format('j M Y, H:i') }}. Last day {{ $r->leavingOn()->format('j M Y') }}, {{ $r->noticeDays() }} days’ notice{{ $r->reason ? '. Reason given: '.strtolower(\App\Models\Resignation::REASONS[$r->reason]) : '' }}.
                            </div>
                            @if ($r->status === 'given')
                                @if ($r->message)<p style="margin:8px 0 0;white-space:pre-line;max-width:68ch;">{{ $r->message }}</p>@endif
                                @if ($short)
                                    <p class="dot-note" style="margin:8px 0 0;color:var(--rg-warn);">Shorter than the minimum notice for their length of service ({{ $r->minimum_notice_days / 7 }} {{ Str::plural('week', $r->minimum_notice_days / 7) }}, BCEA s37). A shorter period works only if you agree to it.</p>
                                @endif
                                <form method="POST" action="{{ route('leaving.acknowledge', $r) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:10px;">
                                    @csrf
                                    <div><label for="ag-{{ $r->id }}">Agreed last day, if different</label><input id="ag-{{ $r->id }}" name="agreed_last_day" type="date" class="dot-input" min="{{ today()->toDateString() }}"></div>
                                    <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0 0 8px;"><input type="checkbox" name="offer_interview" value="1" checked> Offer an exit interview</label>
                                    <button type="submit" class="dot-btn dot-btn-primary" style="margin-bottom:2px;">Acknowledge and start offboarding</button>
                                </form>
                                <p class="dot-note" style="margin:6px 0 0;">A resignation is acknowledged, not approved. Change the last day only if you both agreed it.</p>
                            @else
                                <p style="margin:8px 0 0;white-space:pre-line;max-width:68ch;">{{ $r->withdrawal_note }}</p>
                                <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                                    <form method="POST" action="{{ route('leaving.withdrawal', [$r, 'agree']) }}" style="margin:0;" data-confirm="Agree that {{ $r->employee->first_name }} stays? Their offboarding checklist is cancelled.">@csrf<button type="submit" class="dot-btn dot-btn-primary">Agree, they stay</button></form>
                                    <form method="POST" action="{{ route('leaving.withdrawal', [$r, 'decline']) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">The resignation stands</button></form>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nothing waiting. People give notice from My work, in writing, and it arrives here.</p>
                @endforelse
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Working their notice, or left this month</div>
                @forelse ($leaving as $r)
                    <a href="{{ $r->lifecycle_checklist_id ? route('lifecycle.show', $r->lifecycle_checklist_id) : route('employees.show', $r->employee_id) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;">
                            <h4>{{ $r->employee->fullName() }}</h4>
                            <div class="m">Last day {{ $r->leavingOn()->format('l j M Y') }}{{ $r->agreed_last_day ? ', agreed' : '' }}</div>
                        </div>
                        <span class="dot-badge {{ $r->leavingOn()->isPast() ? 'dot-badge-muted' : 'dot-badge-accent' }}">{{ $r->leavingOn()->isPast() ? 'Left' : today()->diffInDays($r->leavingOn()).' days to go' }}</span>
                    </a>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nobody.</p>
                @endforelse
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Exit interviews</div>
                @forelse ($interviews as $interview)
                    @php
                        $state = $interview->completed_at ? ($interview->mode === 'meeting' ? 'Conversation recorded' : 'Answered') : ($interview->declined_at ? 'Declined' : 'Offered');
                    @endphp
                    <a href="{{ route('leaving.interview', $interview) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;">
                            <h4>{{ $interview->employee->fullName() }}</h4>
                            <div class="m">Offered {{ $interview->offered_at->format('j M Y') }}{{ $interview->completed_at ? ', '.strtolower($state).' '.$interview->completed_at->format('j M') : '' }}</div>
                        </div>
                        <span class="dot-badge {{ ['Answered' => 'dot-badge-ok', 'Conversation recorded' => 'dot-badge-ok', 'Declined' => 'dot-badge-muted', 'Offered' => 'dot-badge-accent'][$state] }}">{{ $state }}</span>
                    </a>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">None offered yet. Offer one when you acknowledge a resignation, or from the person’s record.</p>
                @endforelse
            </div>
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">What leavers tell us</div>
                @if ($patterns)
                    <p class="dot-note" style="margin:4px 0 0;">From {{ $patterns['count'] }} people who agreed to share their answers anonymously. Counts only.</p>
                    @php $max = max($patterns['reasons'] ?: [1]); @endphp
                    <div class="rg-hbars" role="list">
                        @foreach ($patterns['reasons'] as $key => $n)
                            <div class="rg-hbar" role="listitem" style="grid-template-columns:minmax(0,1fr) 70px 2em;">
                                <span>{{ \App\Models\ExitInterview::REASONS[$key] }}</span>
                                <span class="t"><i style="width: {{ max(1, round($n / $max * 100)) }}%;"></i></span>
                                <span class="n">{{ $n }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="dot-note" style="margin:12px 0 0;">Would come back: {{ $patterns['would_return']['yes'] }} yes, {{ $patterns['would_return']['unsure'] }} not sure, {{ $patterns['would_return']['no'] }} no.</p>
                @else
                    <p class="dot-note" style="margin:4px 0 0;">Shown once {{ \App\Models\ExitInterview::ANONYMITY_FLOOR }} people have agreed to share their answers anonymously, so nobody can be picked out.</p>
                @endif
            </div>

            <div class="dot-card dot-card--pad">
                <div class="dot-label">Certificates of service</div>
                @forelse ($certificates as $certificate)
                    <a href="{{ route('leaving.certificate', $certificate) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                        <div style="min-width:0;"><h4 style="font-size:13.5px;">{{ $certificate->employee->fullName() }}</h4><div class="m">Issued {{ $certificate->issued_at->format('j M Y') }}</div></div>
                    </a>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Every leaver is owed one (BCEA s42). Issue it from their record.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-app-layout>
