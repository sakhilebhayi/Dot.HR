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
    <div class="rg-grid">
        @foreach ($openStages as $stage)
            @php $rows = $byStage->get($stage, collect()); @endphp
            <div class="dot-card dot-card--pad s4">
                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                    <div class="dot-label" style="margin:0;">{{ \App\Models\Application::STAGES[$stage] }}</div>
                    <div class="dot-figure" style="font-size:20px;">{{ $rows->count() }}</div>
                </div>
                @forelse ($rows as $row)
                    @php $application = $row['application']; @endphp
                    <a href="{{ route('hiring.applications.show', $application) }}" style="display:block;text-decoration:none;color:inherit;background:var(--rg-ground);border-radius:8px;padding:9px 11px;margin-top:8px;">
                        <div style="font-weight:500;">{{ $application->user->name }}</div>
                        <div class="dot-note">Applied {{ $application->created_at->diffForHumans() }}</div>
                        @if ($row['match'])<div class="dot-note" style="color:var(--rg-accent-ink);">{{ $row['match'] }}</div>@endif
                    </a>
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

    <section class="rg-section">
        <h2>The role as candidates read it</h2>
        <div class="dot-card dot-card--pad" style="white-space:pre-line;">{{ $vacancy->description }}</div>
    </section>
</div>
</x-app-layout>
