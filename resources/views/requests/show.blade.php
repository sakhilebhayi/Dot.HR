<x-app-layout>
<div class="page-pad" style="max-width:760px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('requests.index') }}">Requests</a> · {{ $hrRequest->reference() }}</p>
    <h1 style="margin:0;">{{ $hrRequest->subject }}</h1>
    <p class="rg-sub" style="margin:4px 0 18px;">
        {{ $hrRequest->employee?->fullName() ?? 'Former employee' }} · {{ explode(' (', \App\Models\HrRequest::CATEGORIES[$hrRequest->category] ?? $hrRequest->category)[0] }} · {{ $hrRequest->created_at->format('j M Y, H:i') }} ·
        <span class="dot-badge {{ $hrRequest->status === 'open' ? 'dot-badge-warn' : ($hrRequest->status === 'answered' ? 'dot-badge-ok' : 'dot-badge-muted') }}">{{ \App\Models\HrRequest::STATUSES[$hrRequest->status] }}</span>
    </p>

    <x-flash />

    <div class="dot-card dot-card--pad">
        <div class="dot-label">They asked</div>
        <p style="white-space:pre-line;margin:0;">{{ $hrRequest->body }}</p>
    </div>

    <div class="dot-card dot-card--pad" style="margin-top:14px;">
        <div class="dot-label">Your answer</div>
        @if ($hrRequest->response)
            <p style="white-space:pre-line;margin:0 0 6px;">{{ $hrRequest->response }}</p>
            <p class="dot-note" style="margin:0 0 12px;">{{ $hrRequest->responder?->name ?? 'HR' }} · {{ $hrRequest->responded_at?->format('j M Y, H:i') }}</p>
        @endif
        @if ($hrRequest->status !== 'closed')
            <form method="POST" action="{{ route('requests.answer', $hrRequest) }}" style="display:grid;gap:8px;">
                @csrf
                <label for="response" style="position:absolute;left:-9999px;">Answer</label>
                <textarea id="response" name="response" class="dot-input" rows="4" maxlength="5000" required placeholder="They see this in Dot.HR and get a notification.">{{ old('response') }}</textarea>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label style="display:inline-flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="close" value="1"> Close it with this answer</label>
                    <button type="submit" class="dot-btn dot-btn-primary">{{ $hrRequest->response ? 'Send another answer' : 'Send answer' }}</button>
                </div>
            </form>
            @if ($hrRequest->response)
                <form method="POST" action="{{ route('requests.close', $hrRequest) }}" style="margin-top:8px;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Close without another answer</button></form>
            @endif
        @endif
    </div>
</div>
</x-app-layout>
