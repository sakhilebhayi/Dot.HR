<x-app-layout>
<div class="page-pad">
    @php $active = $plans->where('status', 'active'); @endphp
    <h1 class="rg-greet">
        @if ($active->isEmpty())
            No improvement plans are running.
        @else
            <em>{{ $active->count() }} {{ Str::plural('plan', $active->count()) }}</em> {{ $active->count() === 1 ? 'is' : 'are' }} running.
        @endif
    </h1>
    <p class="rg-sub">Time, support and clear objectives for someone whose work is below standard. Seen by HR, the supporting manager and the person, nobody else.</p>

    <x-flash />

    @if ($admin)
        <p style="margin:0 0 12px;"><a href="{{ route('plans.create') }}" class="dot-btn dot-btn-primary">Draft a plan</a></p>
    @endif

    <div class="dot-card dot-card--pad">
        @forelse ($plans as $plan)
            @php $next = $plan->nextCheckIn(); @endphp
            <a href="{{ route('plans.show', $plan) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $plan->employee->fullName() }}: {{ $plan->title }}</h4>
                    <div class="m">
                        {{ $plan->reference() }}, {{ $plan->starts_on->format('j M') }} to {{ $plan->ends_on->format('j M Y') }}{{ $plan->manager ? ', supported by '.$plan->manager->name : '' }}.
                        @if ($next) Next check-in {{ $next->isPast() ? 'was due '.$next->format('j M') : 'by '.$next->format('j M') }}. @endif
                    </div>
                </div>
                <span class="dot-badge {{ match ($plan->status) { 'active' => ($next?->isPast() ? 'dot-badge-warn' : 'dot-badge-accent'), 'draft' => 'dot-badge-muted', 'completed' => ($plan->outcome === 'met' ? 'dot-badge-ok' : 'dot-badge-warn'), default => 'dot-badge-muted' } }}">
                    {{ $plan->status === 'completed' ? ($plan->outcome === 'met' ? 'Standard met' : 'Not met') : ($next?->isPast() ? 'Check-in due' : $plan->statusLabel()) }}
                </span>
            </a>
        @empty
            <p class="dot-note" style="margin:0;">{{ $admin ? 'No plans yet. Draft one from here or from the person’s record.' : 'No plans name you as the supporting manager.' }}</p>
        @endforelse
    </div>
</div>
</x-app-layout>
