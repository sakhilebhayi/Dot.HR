<x-app-layout>
<div class="page-pad">
    @php
        $firstName = str(auth()->user()->name)->before(' ');
        $pct = (int) round($completeness['done'] / max(1, $completeness['total']) * 100);
    @endphp

    <h1 class="rg-greet">
        {{ $firstName }}, this is your own space —
        @if ($completeness['done'] === $completeness['total'])
            <em>your profile is complete</em>.
        @else
            <em>{{ $completeness['total'] - $completeness['done'] }} {{ Str::plural('step', $completeness['total'] - $completeness['done']) }}</em> from a complete profile.
        @endif
    </h1>
    <p class="rg-sub">It belongs to you, not to any employer. It goes with you whichever business you work in.</p>

    <x-flash />

    <div class="rg-grid">
        <div class="dot-card dot-card--pad s8">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
                <div>
                    <div class="dot-label">Your profile</div>
                    <div style="font-size:17px;font-weight:500;">{{ $profile->headline ?: 'No headline yet' }}</div>
                    <div class="dot-note">
                        {{ \App\Models\ProfessionalProfile::AVAILABILITY[$profile->availability] ?? '' }}
                        @if ($profile->location) · {{ $profile->location }} @endif
                    </div>
                </div>
                <a href="{{ route('me.profile') }}" class="dot-btn dot-btn-primary">Edit your profile</a>
            </div>

            <div style="margin-top:18px;">
                <div style="display:flex;justify-content:space-between;" class="dot-note">
                    <span>{{ $completeness['done'] }} of {{ $completeness['total'] }} parts filled</span>
                    <span class="dot-figure">{{ $pct }}%</span>
                </div>
                <div class="rg-track" style="margin-top:6px;"><i style="width: {{ $pct }}%"></i></div>
                @if ($completeness['missing'] !== [])
                    <p class="dot-note" style="margin:10px 0 0;">Still to add: {{ implode(', ', $completeness['missing']) }}.</p>
                @endif
            </div>

            @if ($profile->skills->isNotEmpty())
                <div style="margin-top:18px;">
                    <div class="dot-label">Skills</div>
                    <div class="rg-chips">
                        @foreach ($profile->skills as $skill)
                            <span class="rg-chip" style="padding-right:11px;">{{ $skill->name }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="dot-card dot-card--pad s4">
            <div class="dot-label">Who can see it</div>
            <div style="font-weight:500;">{{ \App\Models\ProfessionalProfile::VISIBILITY[$profile->visibility] }}</div>
            <p class="dot-note" style="margin:8px 0 0;">Your email address and phone number are never part of your profile. Employers reach you through Dot.HR.</p>
            @if ($profile->visibility === 'public')
                <div class="dot-label" style="margin-top:14px;">Share link</div>
                <a href="{{ route('people.show', $profile->handle) }}" class="dot-note" style="overflow-wrap:anywhere;">{{ route('people.show', $profile->handle) }}</a>
            @endif
        </div>

        <div class="dot-card dot-card--pad s12">
            <div class="dot-label">Your workspaces</div>
            @forelse ($businesses as $business)
                @php $roleLabel = $business->user_id === auth()->id() ? 'Owner' : (auth()->user()->teamRole($business)?->name ?? 'Member'); @endphp
                @if (auth()->user()->current_team_id === $business->id)
                    <a href="{{ route('dashboard') }}" class="ws-item" style="flex-direction:row;justify-content:space-between;align-items:center;">
                        <span class="ws-name">{{ $business->name }}</span>
                        <span class="ws-sub">{{ $roleLabel }} · open</span>
                    </a>
                @else
                    <form method="POST" action="{{ route('current-team.update') }}" style="margin:0;">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="team_id" value="{{ $business->id }}">
                        <button type="submit" class="ws-item" style="flex-direction:row;justify-content:space-between;align-items:center;">
                            <span class="ws-name">{{ $business->name }}</span>
                            <span class="ws-sub">{{ $roleLabel }} · open</span>
                        </button>
                    </form>
                @endif
            @empty
                <p class="dot-note" style="margin:4px 0 0;">You are not part of a business on Dot.HR yet. Businesses appear here when InfoDot lists you as one of their people.</p>
            @endforelse
        </div>
    </div>

    <section class="rg-section">
        <h2>Your applications</h2>
        <div class="dot-card dot-card--pad">
            @forelse ($applications as $application)
                @php $next = $application->interviews->where('status', 'scheduled')->filter(fn ($i) => $i->starts_at->isFuture())->first(); @endphp
                <a href="{{ route('me.applications.show', $application->id) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                    <div style="min-width:0;">
                        <h4>{{ $application->vacancy->title }} — {{ $application->vacancy->team->name }}</h4>
                        <div class="m">@if ($next) Interview {{ $next->starts_at->format('D j M, H:i') }} @else Applied {{ $application->created_at->format('j M Y') }} @endif</div>
                    </div>
                    <span class="dot-badge {{ $application->stage === 'offer' ? 'dot-badge-ok' : 'dot-badge-accent' }}">{{ $application->candidateStageLabel() }}</span>
                </a>
            @empty
                <p class="dot-note" style="margin:0 0 10px;">Nothing in progress.</p>
                <a href="{{ route('jobs.index') }}" class="dot-btn">Browse open roles</a>
            @endforelse
        </div>
    </section>
</div>
</x-app-layout>
