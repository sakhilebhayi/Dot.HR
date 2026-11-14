{{-- The Register's own mark for the dashboard: a ruled ledger page with a column of ticks, drawn
     from the design tokens. Decorative only (hidden from assistive technology). --}}
@if (($variant ?? 'band') === 'band')
<div class="dash-band-art" aria-hidden="true">
    <svg class="dash-art" viewBox="0 0 200 52" fill="none" stroke-width="1" role="presentation" focusable="false">
        <g class="ink"><path d="M0 12H200M0 38H200M34 2V52"/></g>
        <g class="tick" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 7l4 4 8-9"/><path d="M8 33l4 4 8-9" opacity="0.4"/>
        </g>
    </svg>
</div>
@else
<svg class="dash-art" viewBox="0 0 132 72" fill="none" stroke-width="1" aria-hidden="true" focusable="false" role="presentation">
    <g class="ink"><path d="M0 12H132M0 30H132M0 48H132M0 66H132M30 2V72"/></g>
    <g class="tick" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 8l4 4 7-8"/><path d="M8 26l4 4 7-8" opacity="0.6"/><path d="M8 44l4 4 7-8" opacity="0.3"/></g>
</svg>
@endif
