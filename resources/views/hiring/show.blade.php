<x-app-layout>
<div class="page-pad">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('hiring.index') }}">Hiring</a> · {{ $vacancy->reference() }}</p>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div style="min-width:0;">
            <h1 style="margin:0;">{{ $vacancy->title }}</h1>
            <p class="rg-sub" style="margin:4px 0 0;">
                <span class="dot-badge {{ ['published' => 'dot-badge-ok', 'draft' => 'dot-badge-muted', 'paused' => 'dot-badge-warn', 'closed' => 'dot-badge-muted'][$vacancy->status] }}">{{ \App\Models\Vacancy::STATUSES[$vacancy->status] }}</span>
                {{ $vacancy->workTypeLabel() }} · {{ $vacancy->arrangementLabel() }}@if ($vacancy->location) · {{ $vacancy->location }}@endif · {{ $vacancy->payLabel() }}
                @if ($vacancy->closes_on) · closes {{ $vacancy->closes_on->format('j M Y') }} @endif
            </p>
        </div>
        @if ($canRecruit)
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="{{ route('hiring.edit', $vacancy) }}" class="dot-btn">Edit</a>
                @foreach (['publish' => ['draft', 'paused'], 'pause' => ['published'], 'close' => ['published', 'paused', 'draft'], 'reopen' => ['closed']] as $to => $from)
                    @if (in_array($vacancy->status, $from, true))
                        <form method="POST" action="{{ route('hiring.transition', [$vacancy, $to]) }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="dot-btn {{ $to === 'publish' ? 'dot-btn-primary' : ($to === 'close' ? 'dot-btn-danger' : '') }}">{{ ucfirst($to) }}</button>
                        </form>
                    @endif
                @endforeach
                <a href="{{ route('hiring.steps') }}" class="dot-btn dot-btn-ghost">Hiring steps</a>
                <form method="POST" action="{{ route('hiring.duplicate', $vacancy) }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="dot-btn dot-btn-ghost">Duplicate</button>
                </form>
            </div>
        @endif
    </div>

    <div style="margin-top:18px;"><x-flash /></div>

    @if ($vacancy->isOpen())
        <p class="dot-note" style="margin:0 0 16px;">Live on the job board: <a href="{{ route('jobs.show', $vacancy->id) }}">{{ route('jobs.show', $vacancy->id) }}</a></p>
    @endif

    @php $openStages = ['new', 'review', 'shortlisted', 'interview', 'offer']; @endphp
    @if ($canRecruit && $byStage->only(['new', 'review', 'shortlisted', 'interview'])->flatten(1)->isNotEmpty())
        <form method="POST" action="{{ route('hiring.bulk', $vacancy) }}" id="bulk-form" data-confirm="Move the ticked candidates? Anyone moved to not progressing is told." class="dot-card dot-card--pad" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
            @csrf
            <div>
                <label for="bulk-stage">Move the ticked candidates to</label>
                <select id="bulk-stage" name="stage" class="dot-input">
                    <option value="review">{{ \App\Models\Application::STAGES['review'] }}</option>
                    <option value="shortlisted">{{ \App\Models\Application::STAGES['shortlisted'] }}</option>
                    <option value="rejected">{{ \App\Models\Application::STAGES['rejected'] }}</option>
                </select>
            </div>
            <div style="flex:1;min-width:220px;">
                <label for="bulk-message">Message to those not progressing (optional)</label>
                <input id="bulk-message" name="message" class="dot-input" maxlength="1000" placeholder="Your application for {{ $vacancy->title }} is not progressing. Thank you for applying.">
            </div>
            <button type="submit" class="dot-btn dot-btn-primary">Move</button>
            <p class="dot-note" style="margin:0;flex-basis:100%;">Tick candidates below. Interviews are set one at a time from the application, and offers follow the offer.</p>
        </form>
    @endif
    <div class="rg-grid">
        @foreach ($openStages as $stage)
            @php $rows = $byStage->get($stage, collect()); @endphp
            <div class="dot-card dot-card--pad s4">
                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                    <div class="dot-label" style="margin:0;">{{ \App\Models\Application::STAGES[$stage] }}</div>
                    <div class="dot-figure" style="font-size:20px;">{{ $rows->count() }}</div>
                </div>
                @if ($canRecruit && $stage !== 'offer' && $rows->isNotEmpty())
                    <button type="button" class="dot-btn dot-btn-ghost" data-check-all="{{ $stage }}" style="min-height:26px;padding:1px 8px;font-size:12.5px;margin-top:6px;">Select all</button>
                @endif
                @forelse ($rows as $row)
                    @php $application = $row['application']; @endphp
                    <div style="display:flex;gap:8px;align-items:flex-start;background:var(--rg-ground);border-radius:8px;padding:9px 11px;margin-top:8px;">
                        @if ($canRecruit && $stage !== 'offer')
                            <input type="checkbox" name="application_ids[]" value="{{ $application->id }}" form="bulk-form" data-check-group="{{ $stage }}" aria-label="Select {{ $application->user->name }}" style="margin-top:4px;">
                        @endif
                        <a href="{{ route('hiring.applications.show', $application) }}" style="display:block;text-decoration:none;color:inherit;min-width:0;flex:1;">
                            <div style="font-weight:500;">{{ $application->user->name }}</div>
                            <div class="dot-note">Applied {{ $application->created_at->diffForHumans() }}</div>
                            @if ($application->step)<div class="dot-note" style="color:var(--rg-ink);">{{ $application->step->name }}</div>@endif
                            @if ($row['match'])<div class="dot-note" style="color:var(--rg-accent-ink);">{{ $row['match'] }}</div>@endif
                        </a>
                    </div>
                @empty
                    <p class="dot-note" style="margin:8px 0 0;">Nobody here.</p>
                @endforelse
            </div>
        @endforeach
    </div>

    @php $closedRows = collect(['hired', 'rejected', 'withdrawn'])->flatMap(fn ($s) => $byStage->get($s, collect())); @endphp
    @if ($closedRows->isNotEmpty())
        <section class="rg-section">
            <h2>Decided</h2>
            <div class="dot-card dot-card--pad">
                @foreach ($closedRows as $row)
                    <div class="rg-entry">
                        <div><h4><a href="{{ route('hiring.applications.show', $row['application']) }}" style="text-decoration:none;">{{ $row['application']->user->name }}</a></h4></div>
                        <span class="dot-badge {{ $row['application']->stage === 'hired' ? 'dot-badge-ok' : 'dot-badge-muted' }}">{{ $row['application']->stageLabel() }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Internal talent (R-32 Phase 7): people already here who hold some of what the role asks for. --}}
    @if (! empty($vacancy->skills))
        <section class="rg-section">
            <h2>Already working here</h2>
            <div class="dot-card dot-card--pad">
                <p class="dot-note" style="margin:0 0 4px;">People in the business who hold some of the {{ count($vacancy->skills) }} skills this role lists, in name order. Evidence comes only from their own profile (where they let you see it), certificates on file and training completed — nothing about who they are. Nobody is ranked, and nothing happens unless you choose to tell them.</p>
                @forelse ($internal as $row)
                    <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                        <div style="min-width:0;">
                            <h4>{{ $row['employee']->fullName() }} <span class="dot-note" style="font-weight:400;">— {{ count($row['matched']) }} of {{ $row['total'] }}</span></h4>
                            <div class="rg-chips" style="margin-top:6px;">
                                @foreach ($row['matched'] as $m)<span class="dot-badge dot-badge-ok">{{ $m['name'] }} · {{ \App\Services\Intelligence\SkillsEvidence::SOURCES[$m['source']] }}</span>@endforeach
                                @foreach ($row['missing'] as $name)<span class="dot-badge dot-badge-muted">{{ $name }} — not on record</span>@endforeach
                            </div>
                        </div>
                        @if ($canRecruit)
                            <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                                @if ($row['invited'])
                                    <span class="dot-badge dot-badge-accent">Told about it</span>
                                @elseif ($vacancy->isOpen() && $row['employee']->user_id)
                                    <form method="POST" action="{{ route('hiring.internal.invite', [$vacancy, $row['employee']]) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">Tell them about it</button></form>
                                @endif
                                @if ($row['missing'] !== [])
                                    <a href="{{ route('training.index', ['employee_id' => $row['employee']->id, 'for' => $row['missing'][0]]) }}#book" class="dot-btn dot-btn-ghost">Plan training</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="dot-note" style="margin:8px 0 0;">Nobody here has any of these skills on record yet.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($canRecruit)
        @php $totals = $costs->groupBy('currency')->map(fn ($c) => (float) $c->sum(fn ($x) => (float) $x->amount)); @endphp
        <section class="rg-section" id="costs">
            <h2>What this role cost to fill</h2>
            <div class="dot-card dot-card--pad">
                @forelse ($costs as $cost)
                    <div class="rg-entry rg-entry--tools" style="align-items:center;">
                        <div style="min-width:0;"><h4>{{ \App\Models\HiringCost::KINDS[$cost->kind] }}{{ $cost->description ? ': '.$cost->description : '' }}</h4><div class="m">{{ $cost->incurred_on->format('j M Y') }}</div></div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span class="dot-figure">{{ $cost->currency }} {{ number_format((float) $cost->amount, 2, '.', ' ') }}</span>
                            <form method="POST" action="{{ route('hiring.costs.destroy', $cost) }}" style="margin:0;" data-confirm="Remove this cost?">@csrf @method('DELETE')<button type="submit" class="dot-btn dot-btn-ghost" style="min-height:28px;padding:2px 8px;">Remove</button></form>
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:0;">No costs recorded. Advertising, an agency fee, assessments: record them here to see what this hire cost.</p>
                @endforelse
                @foreach ($totals as $currency => $total)
                    <p style="margin:12px 0 0;">
                        Total {{ $currency }} {{ number_format($total, 2, '.', ' ') }}@if ($hires > 0), so {{ $currency }} {{ number_format($total / $hires, 2, '.', ' ') }} per hire ({{ $hires }} {{ Str::plural('hire', $hires) }})@else, no hires yet @endif.
                        <span class="dot-note">Recorded outside costs only; staff time is not counted.</span>
                    </p>
                @endforeach
                <details style="margin-top:12px;">
                    <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Record a cost</summary>
                    <form method="POST" action="{{ route('hiring.costs.store', $vacancy) }}" style="display:grid;gap:8px;margin-top:8px;">
                        @csrf
                        <div class="rg-form-row">
                            <div><label for="hc-kind">What for</label><select id="hc-kind" name="kind" class="dot-input">@foreach (\App\Models\HiringCost::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                            <div><label for="hc-desc">Detail (optional)</label><input id="hc-desc" name="description" class="dot-input" maxlength="160" placeholder="Job board, 30-day listing"></div>
                        </div>
                        <div class="rg-form-row rg-form-row--3">
                            <div><label for="hc-amount">Amount</label><input id="hc-amount" name="amount" type="number" step="0.01" min="0.01" class="dot-input" required></div>
                            <div><label for="hc-cur">Currency</label><input id="hc-cur" name="currency" class="dot-input" maxlength="3" value="{{ $vacancy->currency ?? 'ZAR' }}" required></div>
                            <div><label for="hc-on">When</label><input id="hc-on" name="incurred_on" type="date" class="dot-input" required value="{{ now()->toDateString() }}"></div>
                        </div>
                        <div><button type="submit" class="dot-btn dot-btn-primary">Record it</button></div>
                    </form>
                </details>
            </div>
        </section>
    @endif

    <section class="rg-section">
        <h2>The role as candidates read it</h2>
        <div class="dot-card dot-card--pad" style="white-space:pre-line;">{{ $vacancy->description }}</div>
    </section>
</div>
</x-app-layout>
