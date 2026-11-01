{{-- Shift swaps (brief §8), the person's side: offers to them, open offers they could take,
     and their own offers. Shown only when there is something here. --}}
@if ($swapsForMe->isNotEmpty() || $openOffers->isNotEmpty() || $mySwaps->isNotEmpty())
<div class="dot-card dot-card--pad" id="swaps">
    <div class="dot-label">Shift swaps</div>

    @foreach ($swapsForMe as $swap)
        <div class="rg-entry rg-entry--tools">
            <div style="min-width:0;">
                <h4>{{ $swap->from->first_name }} {{ $swap->isSwap() ? 'asks to swap' : 'offers you a shift' }}</h4>
                <div class="m">
                    Their shift: {{ $swap->shift->starts_at->format('D j M') }}, {{ $swap->shift->window() }}.
                    @if ($swap->isSwap()) In exchange for yours: {{ $swap->otherShift->starts_at->format('D j M') }}, {{ $swap->otherShift->window() }}. @endif
                    @if ($swap->note) “{{ $swap->note }}” @endif
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                <form method="POST" action="{{ route('my-work.swaps.accept', $swap) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-primary">Accept</button></form>
                <form method="POST" action="{{ route('my-work.swaps.decline', $swap) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">Decline</button></form>
            </div>
        </div>
    @endforeach

    @foreach ($openOffers as $swap)
        <div class="rg-entry rg-entry--tools">
            <div style="min-width:0;">
                <h4>Up for grabs: {{ $swap->shift->starts_at->format('D j M') }}, {{ $swap->shift->window() }}</h4>
                <div class="m">Offered by {{ $swap->from->first_name }}{{ $swap->note ? '. “'.$swap->note.'”' : '' }}</div>
            </div>
            <form method="POST" action="{{ route('my-work.swaps.accept', $swap) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn">Take it</button></form>
        </div>
    @endforeach

    @foreach ($mySwaps as $swap)
        <div class="rg-entry rg-entry--tools">
            <div style="min-width:0;">
                <h4>Your shift on {{ $swap->shift->starts_at->format('D j M') }}, {{ $swap->shift->window() }}</h4>
                <div class="m">
                    {{ $swap->to ? ($swap->isSwap() ? 'Swap with '.$swap->to->first_name.' for '.$swap->otherShift->starts_at->format('D j M') : 'To '.$swap->to->first_name) : 'Offered to anyone' }}.
                    {{ $swap->decision_note }}
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                <span class="dot-badge {{ ['offered' => 'dot-badge-muted', 'accepted' => 'dot-badge-accent', 'approved' => 'dot-badge-ok', 'declined' => 'dot-badge-warn', 'cancelled' => 'dot-badge-muted', 'expired' => 'dot-badge-muted'][$swap->status] }}">{{ $swap->statusLabel() }}</span>
                @if ($swap->isOpen())
                    <form method="POST" action="{{ route('my-work.swaps.cancel', $swap) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Withdraw</button></form>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
