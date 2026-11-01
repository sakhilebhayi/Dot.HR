<x-app-layout>
<div class="page-pad">
    @php $openCount = $counts->sum(); @endphp
    <h1 class="rg-greet">
        @if ($openCount === 0)
            No cases are open.
        @else
            <em>{{ $openCount }} {{ Str::plural('case', $openCount) }}</em> {{ $openCount === 1 ? 'is' : 'are' }} open.
        @endif
    </h1>
    <p class="rg-sub">Grievances, disciplinary cases and workplace incidents. Confidential: never shown to managers, and never to the person a grievance is about.</p>

    <x-flash />

    @if ($dueReports > 0)
        <div class="dot-flash dot-flash-err" role="alert">
            {{ $dueReports }} work {{ Str::plural('injury', $dueReports) }} not yet marked as reported to the Compensation Fund. A work injury is reported within 7 days of the business being told.
        </div>
    @endif

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:4px;">
        <nav class="rg-tabs" aria-label="Filter cases" style="margin:0;">
            <a href="{{ route('cases.index', ['state' => $state]) }}" @if (! $type) aria-current="page" @endif>All</a>
            @foreach (\App\Models\HrCase::TYPES as $key => $label)
                <a href="{{ route('cases.index', ['state' => $state, 'type' => $key]) }}" @if ($type === $key) aria-current="page" @endif>{{ $label }}{{ $counts->get($key) ? ' ('.$counts->get($key).')' : '' }}</a>
            @endforeach
        </nav>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="{{ route('cases.index', array_filter(['state' => $state === 'open' ? 'closed' : 'open', 'type' => $type])) }}" class="dot-btn dot-btn-ghost">{{ $state === 'open' ? 'Closed cases' : 'Open cases' }}</a>
            <a href="{{ route('cases.create', ['type' => $type ?? 'disciplinary']) }}" class="dot-btn dot-btn-primary">Open a case</a>
        </div>
    </div>

    <div class="dot-card dot-card--pad" style="margin-top:12px;">
        @forelse ($cases as $case)
            <a href="{{ route('cases.show', $case) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $case->title }}</h4>
                    <div class="m">
                        {{ $case->reference() }}, {{ strtolower($case->typeLabel()) }}{{ $case->employee ? ' about '.$case->employee->fullName() : '' }}.
                        Opened {{ $case->created_at->format('j M Y') }}{{ $case->handler ? ', handled by '.$case->handler->name : ', no handler yet' }}.
                        @if ($case->restricted) Restricted. @endif
                    </div>
                </div>
                <span class="dot-badge {{ match ($case->status) { 'open', 'appealed' => 'dot-badge-warn', 'investigating', 'hearing' => 'dot-badge-accent', 'decided' => 'dot-badge-ok', default => 'dot-badge-muted' } }}">{{ $case->statusLabel() }}</span>
            </a>
        @empty
            <p class="dot-note" style="margin:0;">
                {{ $state === 'open' ? 'Nothing open. Employees lodge grievances from My work; you open disciplinary cases and incident records here.' : 'No closed cases yet.' }}
            </p>
        @endforelse
    </div>
</div>
</x-app-layout>
