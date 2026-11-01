<x-app-layout>
<div class="page-pad">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('schedule.index') }}">Schedule</a></p>
    <h1 class="rg-greet">
        @if ($waiting->isEmpty())
            No shift swaps are waiting for you.
        @else
            <em>{{ $waiting->count() }} {{ Str::plural('swap', $waiting->count()) }}</em> {{ $waiting->count() === 1 ? 'is' : 'are' }} waiting for your approval.
        @endif
    </h1>
    <p class="rg-sub">Colleagues agree a swap between themselves; you approve it, and only then does the schedule change. Overlapping shifts and approved leave are checked again when you approve.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        <div class="dot-label">Agreed, waiting for you</div>
        @forelse ($waiting as $swap)
            <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                <div style="min-width:0;">
                    <h4>{{ $swap->from->fullName() }} {{ $swap->isSwap() ? 'swaps with' : 'hands over to' }} {{ $swap->to->fullName() }}</h4>
                    <div class="m">
                        {{ $swap->to->first_name }} takes {{ $swap->shift->starts_at->format('D j M') }}, {{ $swap->shift->window() }}{{ $swap->shift->title ? ' ('.$swap->shift->title.')' : '' }}.
                        @if ($swap->isSwap()) {{ $swap->from->first_name }} takes {{ $swap->otherShift->starts_at->format('D j M') }}, {{ $swap->otherShift->window() }}. @endif
                        Accepted {{ $swap->accepted_at->diffForHumans() }}.
                        @if ($swap->note) “{{ $swap->note }}” @endif
                    </div>
                    <form method="POST" action="{{ route('schedule.swaps.decide', [$swap, 'approve']) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
                        @csrf
                        <label for="note-{{ $swap->id }}" style="position:absolute;left:-9999px;">Note</label>
                        <input id="note-{{ $swap->id }}" name="note" class="dot-input" maxlength="255" placeholder="A note for them (optional)" style="flex:1;min-width:180px;">
                        <button type="submit" class="dot-btn dot-btn-primary">Approve</button>
                        <button type="submit" class="dot-btn" formaction="{{ route('schedule.swaps.decide', [$swap, 'decline']) }}">Decline</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="dot-note" style="margin:6px 0 0;">Nothing to approve. People offer swaps from their own shifts in My work.</p>
        @endforelse
    </div>

    @if ($offered->isNotEmpty())
        <div class="dot-card dot-card--pad" style="margin-top:14px;">
            <div class="dot-label">Offered, not yet taken</div>
            @foreach ($offered as $swap)
                <div class="rg-entry">
                    <div style="min-width:0;"><h4>{{ $swap->from->fullName() }}: {{ $swap->shift->starts_at->format('D j M') }}, {{ $swap->shift->window() }}</h4><div class="m">{{ $swap->to ? 'Offered to '.$swap->to->fullName() : 'Open to anyone' }}</div></div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <div class="dot-card dot-card--pad" style="margin-top:14px;">
            <div class="dot-label">The last 30 days</div>
            @foreach ($recent as $swap)
                <div class="rg-entry">
                    <div style="min-width:0;"><h4>{{ $swap->from->fullName() }}{{ $swap->to ? ' and '.$swap->to->fullName() : '' }}</h4><div class="m">{{ $swap->shift->starts_at->format('D j M') }}, {{ $swap->shift->window() }}</div></div>
                    <span class="dot-badge {{ $swap->status === 'approved' ? 'dot-badge-ok' : 'dot-badge-muted' }}">{{ $swap->statusLabel() }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
</x-app-layout>
