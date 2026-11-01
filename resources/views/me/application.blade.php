<x-app-layout>
<div class="page-pad" style="max-width:860px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('me.applications.index') }}">Your applications</a> · {{ $application->reference() }}</p>
    <h1 class="rg-greet">{{ $application->vacancy->title }}</h1>
    <p class="rg-sub">
        {{ $application->vacancy->team->name }} ·
        <span class="dot-badge {{ in_array($application->stage, ['offer', 'hired'], true) ? 'dot-badge-ok' : ($application->isOpen() ? 'dot-badge-accent' : 'dot-badge-muted') }}">{{ $application->candidateStageLabel() }}</span>
        · applied {{ $application->created_at->format('j M Y') }}
    </p>

    <x-flash />

    @if ($offer)
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
            <div class="dot-label">The offer</div>
            <div style="font-size:18px;font-weight:500;">{{ $offer->job_title }}</div>
            <div class="dot-figure" style="margin-top:2px;">{{ $offer->payLabel() }} · starting {{ $offer->starts_on->format('j M Y') }}</div>
            @if ($offer->terms)<p style="white-space:pre-line;margin:12px 0 0;">{{ $offer->terms }}</p>@endif

            @if ($offer->status === 'sent')
                <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
                    <form method="POST" action="{{ route('me.applications.respond', [$application->id, 'accept']) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-primary">Accept the offer</button>
                    </form>
                    <form method="POST" action="{{ route('me.applications.respond', [$application->id, 'decline']) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="dot-btn">Decline</button>
                    </form>
                </div>
                <p class="dot-note" style="margin:10px 0 0;">Accepting creates your employee record at {{ $application->vacancy->team->name }}. It is not a signed contract — your employer will share that with you.</p>
            @else
                <p style="margin:12px 0 0;"><span class="dot-badge {{ $offer->status === 'accepted' ? 'dot-badge-ok' : 'dot-badge-muted' }}">{{ ucfirst($offer->status) }}</span> {{ $offer->responded_at?->format('j M Y') }}</p>
            @endif
        </div>
    @endif

    <div class="dot-card dot-card--pad" style="margin-bottom:14px;">
        <div class="dot-label">Interviews</div>
        @forelse ($interviews as $interview)
            <div class="rg-entry">
                <div>
                    <h4>{{ $interview->starts_at->format('l j F Y, H:i') }} · {{ $interview->duration_minutes }} minutes</h4>
                    <div class="m">{{ $interview->modeLabel() }}@if ($interview->where) · {{ $interview->where }}@endif</div>
                    @if ($interview->candidate_note)<p style="margin:6px 0 0;white-space:pre-line;">{{ $interview->candidate_note }}</p>@endif
                </div>
            </div>
        @empty
            <p class="dot-note" style="margin:0;">No interview yet. If {{ $application->vacancy->team->name }} invites you, it appears here and in your notifications.</p>
        @endforelse
    </div>

    <div class="dot-card dot-card--pad">
        <div class="dot-label">What you sent</div>
        @if ($application->cover_note)<p style="white-space:pre-line;margin:0 0 10px;">{{ $application->cover_note }}</p>@endif
        @foreach ($application->vacancy->screening_questions ?? [] as $i => $question)
            <div class="dot-note">{{ $question }}</div>
            <p style="margin:2px 0 10px;white-space:pre-line;">{{ $application->answers[$i] ?? '—' }}</p>
        @endforeach
        <p class="dot-note" style="margin:0;">Plus your <a href="{{ route('me.profile') }}">Dot.HR profile</a> and your email address.</p>
    </div>

    @if ($application->isOpen())
        <form method="POST" action="{{ route('me.applications.withdraw', $application->id) }}" style="margin-top:18px;" data-confirm="Withdraw this application? The business will see that you withdrew.">
            @csrf
            <button type="submit" class="dot-btn dot-btn-danger">Withdraw my application</button>
        </form>
    @endif
</div>
</x-app-layout>
