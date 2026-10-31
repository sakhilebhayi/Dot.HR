<x-app-layout>
<div class="page-pad" style="max-width:900px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('jobs.index') }}">Job board</a> · {{ $vacancy->reference() }}</p>
    <h1 class="rg-greet">{{ $vacancy->title }}</h1>
    <p class="rg-sub">
        {{ $vacancy->team->name }} · {{ $vacancy->workTypeLabel() }} · {{ $vacancy->arrangementLabel() }}@if ($vacancy->location) · {{ $vacancy->location }}@endif
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad" style="white-space:pre-line;">{{ $vacancy->description }}</div>
            @if ($vacancy->responsibilities)
                <div class="dot-card dot-card--pad"><div class="dot-label">What you'll do</div><div style="white-space:pre-line;">{{ $vacancy->responsibilities }}</div></div>
            @endif
            @if ($vacancy->requirements)
                <div class="dot-card dot-card--pad"><div class="dot-label">What you'll need</div><div style="white-space:pre-line;">{{ $vacancy->requirements }}</div></div>
            @endif
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Pay</div>
                <div style="font-size:17px;" class="dot-figure">{{ $vacancy->payLabel() }}</div>
                @if ($vacancy->hours)<div class="dot-label" style="margin-top:10px;">Hours</div><div>{{ $vacancy->hours }}</div>@endif
                @if ($vacancy->starts_on)<div class="dot-label" style="margin-top:10px;">Starts</div><div>{{ $vacancy->starts_on->format('j M Y') }}</div>@endif
                <div class="dot-label" style="margin-top:10px;">Applications close</div>
                <div>{{ $vacancy->closes_on?->format('j M Y') ?? 'When the role is filled' }}</div>
                @if ($vacancy->openings > 1)<div class="dot-label" style="margin-top:10px;">Openings</div><div class="dot-figure">{{ $vacancy->openings }}</div>@endif
            </div>
            @if (($vacancy->skills ?? []) !== [])
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Skills asked for</div>
                    <div class="rg-chips">@foreach ($vacancy->skills as $skill)<span class="rg-chip" style="padding-right:11px;">{{ $skill }}</span>@endforeach</div>
                    @if ($match)<p class="dot-note" style="margin:10px 0 0;color:var(--rg-accent-ink);">Your profile {{ $match }}.</p>@endif
                </div>
            @endif
        </div>
    </div>

    <section class="rg-section" id="apply">
        <h2>Apply</h2>
        <div class="dot-card dot-card--pad">
            @guest
                <p style="margin:0 0 10px;">Sign in with your InfoDot account to apply. Your Dot.HR profile goes with you.</p>
                <a href="{{ route('login') }}" class="dot-btn dot-btn-primary">Sign in to apply</a>
            @else
                @if ($existing)
                    <p style="margin:0 0 10px;">You applied on {{ $existing->created_at->format('j M Y') }} — it is <strong>{{ Str::lower($existing->candidateStageLabel()) }}</strong>.</p>
                    <a href="{{ route('me.applications.show', $existing->id) }}" class="dot-btn">Follow your application</a>
                @else
                    <form method="POST" action="{{ route('jobs.apply', $vacancy->id) }}" style="display:grid;gap:12px;">
                        @csrf
                        @foreach ($vacancy->screening_questions ?? [] as $i => $question)
                            <div>
                                <label for="answer-{{ $i }}">{{ $question }}</label>
                                <textarea id="answer-{{ $i }}" name="answers[{{ $i }}]" class="dot-input" rows="2" maxlength="2000" required>{{ old('answers.'.$i) }}</textarea>
                            </div>
                        @endforeach
                        <div>
                            <label for="cover_note">A note to {{ $vacancy->team->name }} <span class="dot-note">(optional)</span></label>
                            <textarea id="cover_note" name="cover_note" class="dot-input" rows="4" maxlength="4000" placeholder="Why this role, in a few lines.">{{ old('cover_note') }}</textarea>
                        </div>
                        @if (! $profile || ! $profile->headline)
                            <p class="dot-note" style="margin:0;">Your profile is thin — <a href="{{ route('me.profile') }}">add your skills and history</a> first so {{ $vacancy->team->name }} sees your best side.</p>
                        @endif
                        <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;">
                            <input type="checkbox" name="share" value="1" required style="margin-top:3px;">
                            <span>Share my Dot.HR profile and my email address ({{ auth()->user()->email }}) with {{ $vacancy->team->name }} for this application. Only this business sees them.</span>
                        </label>
                        <div><button type="submit" class="dot-btn dot-btn-primary">Send application</button></div>
                    </form>
                @endif
            @endguest
        </div>
    </section>
</div>
</x-app-layout>
