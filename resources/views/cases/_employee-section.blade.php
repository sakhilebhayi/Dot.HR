{{-- Employee relations on a person's record (brief §15): only for those who handle cases,
     and only the cases they may open. Kept apart from ordinary notes on purpose. --}}
@php $warnings = $cases->filter(fn ($c) => $c->warningActive()); @endphp
<div class="dot-card dot-card--pad" id="cases" style="margin-top:14px;">
    <div class="dot-label">
        Cases{{ $warnings->isNotEmpty() ? ', '.$warnings->count().' '.Str::plural('warning', $warnings->count()).' still counting' : '' }}
    </div>
    @forelse ($cases as $case)
        <a href="{{ route('cases.show', $case) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
            <div style="min-width:0;">
                <h4>{{ $case->reference() }} {{ $case->title }}</h4>
                <div class="m">{{ $case->typeLabel() }}, opened {{ $case->created_at->format('j M Y') }}{{ $case->outcome ? ', '.strtolower($case->outcomeLabel()) : '' }}</div>
            </div>
            @if ($case->warningActive())
                <span class="dot-badge dot-badge-warn">Warning until {{ $case->warning_expires_on?->format('j M Y') }}</span>
            @else
                <span class="dot-badge {{ $case->isOpen() ? 'dot-badge-accent' : 'dot-badge-muted' }}">{{ $case->statusLabel() }}</span>
            @endif
        </a>
    @empty
        <p class="dot-note" style="margin:6px 0 0;">No cases about {{ $employee->first_name }}.</p>
    @endforelse
    @if (! $employee->trashed())
        <p style="margin:10px 0 0;"><a href="{{ route('cases.create', ['type' => 'disciplinary']) }}" class="dot-btn dot-btn-ghost">Open a case</a></p>
    @endif
</div>
