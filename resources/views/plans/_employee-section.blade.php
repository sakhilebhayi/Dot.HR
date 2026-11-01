{{-- Improvement plans on a person's record: HR administrators only. --}}
<div class="dot-card dot-card--pad" id="plans" style="margin-top:14px;">
    <div class="dot-label">Improvement plans</div>
    @forelse ($plans as $plan)
        <a href="{{ route('plans.show', $plan) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
            <div style="min-width:0;">
                <h4>{{ $plan->reference() }} {{ $plan->title }}</h4>
                <div class="m">{{ $plan->starts_on->format('j M Y') }} to {{ $plan->ends_on->format('j M Y') }}</div>
            </div>
            <span class="dot-badge {{ $plan->status === 'active' ? 'dot-badge-accent' : ($plan->outcome === 'not_met' ? 'dot-badge-warn' : 'dot-badge-muted') }}">{{ $plan->status === 'completed' ? ($plan->outcome === 'met' ? 'Standard met' : 'Not met') : $plan->statusLabel() }}</span>
        </a>
    @empty
        <p class="dot-note" style="margin:6px 0 0;">None. A plan gives clear objectives, support and time when work is below standard.</p>
    @endforelse
    @if (! $employee->trashed())
        <p style="margin:10px 0 0;"><a href="{{ route('plans.create', ['employee' => $employee->id]) }}" class="dot-btn dot-btn-ghost">Draft a plan</a></p>
    @endif
</div>
