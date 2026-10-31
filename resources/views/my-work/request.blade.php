<x-app-layout>
<div class="page-pad" style="max-width:760px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('my-work.index') }}">My work</a> · {{ $hrRequest->reference() }}</p>
    <h1 style="margin:0;">{{ $hrRequest->subject }}</h1>
    <p class="rg-sub" style="margin:4px 0 18px;">
        {{ explode(' (', \App\Models\HrRequest::CATEGORIES[$hrRequest->category] ?? $hrRequest->category)[0] }} · sent {{ $hrRequest->created_at->format('j M Y, H:i') }} ·
        <span class="dot-badge {{ $hrRequest->status === 'open' ? 'dot-badge-warn' : ($hrRequest->status === 'answered' ? 'dot-badge-ok' : 'dot-badge-muted') }}">{{ \App\Models\HrRequest::STATUSES[$hrRequest->status] }}</span>
    </p>

    <x-flash />

    <div class="dot-card dot-card--pad">
        <div class="dot-label">You asked</div>
        <p style="white-space:pre-line;margin:0;">{{ $hrRequest->body }}</p>
    </div>

    <div class="dot-card dot-card--pad" style="margin-top:14px;">
        <div class="dot-label">HR's answer</div>
        @if ($hrRequest->response)
            <p style="white-space:pre-line;margin:0;">{{ $hrRequest->response }}</p>
            <p class="dot-note" style="margin:8px 0 0;">{{ $hrRequest->responded_at?->format('j M Y, H:i') }}</p>
        @else
            <p class="dot-note" style="margin:0;">Not answered yet. You'll get a notification when HR replies.</p>
        @endif
    </div>
</div>
</x-app-layout>
