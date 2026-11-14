{{-- The Register's own mark for the dashboard: a ruled ledger page with a margin column, drawn
     from the design tokens. The band strip carries two stamped ticks; the empty-state page carries
     empty tick boxes, rows still waiting to be filled. Decorative only (hidden from assistive technology). --}}
@if (($variant ?? 'band') === 'band')
<div class="dash-band-art" aria-hidden="true">
    <svg class="dash-art" viewBox="0 0 132 34" fill="none" stroke-width="1" role="presentation" focusable="false">
        <g class="ink"><path d="M0 8H132M0 22H132M0 33H132M22 0V34"/></g>
        <g class="tick" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 4l3 3 6-7"/><path d="M5 18l3 3 6-7" opacity="0.45"/>
        </g>
    </svg>
</div>
@else
<svg class="dash-art dash-art--empty" viewBox="0 0 132 72" fill="none" stroke-width="1" aria-hidden="true" focusable="false" role="presentation">
    <g class="ink"><path d="M0 14H132M0 32H132M0 50H132M0 68H132M30 2V72"/></g>
    <g stroke="currentColor" stroke-width="1.3" opacity="0.6">
        <rect class="box" x="8" y="3" width="11" height="9" rx="2"/><rect class="box" x="8" y="21" width="11" height="9" rx="2"/><rect class="box" x="8" y="39" width="11" height="9" rx="2"/>
    </g>
</svg>
@endif
