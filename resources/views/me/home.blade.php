<x-app-layout>
@push('head')
    @vite(['resources/css/dashboard.css'])
@endpush
<div class="page-pad"><div class="dash">
    @php
        $firstName = str(auth()->user()->name)->before(' ');
        $pct = (int) round($completeness['done'] / max(1, $completeness['total']) * 100);
        $complete = $completeness['done'] === $completeness['total'];
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');
    @endphp
    <a class="dash-skip" href="#dash-main">Skip to your space</a>

    <header class="rg-land">
        @include('partials.dash-art', ['variant' => 'band'])
        <div class="rg-land-mast">
            <img class="rg-land-logo rg-land-logo--day" src="{{ asset('images/logo-trim.png') }}" alt="Dot.HR" width="99" height="88">
            <img class="rg-land-logo rg-land-logo--night" src="{{ asset('images/logo-light-trim.png') }}" alt="Dot.HR" width="99" height="88">
            <nav class="rg-land-back" aria-label="Dot ecosystem">
                <a href="{{ $infodot }}/home">InfoDot</a>
                <a href="{{ $infodot }}/platforms">Platforms</a>
            </nav>
        </div>
        <div class="rg-land-lead">
            <h1 class="rg-greet">
                {{ $firstName }}, this is your own space —
                @if ($complete)
                    <em>your profile is complete</em>.
                @else
                    <em>{{ $completeness['total'] - $completeness['done'] }} {{ Str::plural('step', $completeness['total'] - $completeness['done']) }}</em> from a complete profile.
                @endif
            </h1>
            <p class="rg-land-copy">It belongs to you, not to any employer. It goes with you whichever business you work in.</p>
            <div class="rg-land-actions">
                @if ($complete)
                    <a href="{{ route('jobs.index') }}" class="dot-btn dot-btn-primary rg-land-go" data-primary-action>Browse open roles</a>
                    <a href="{{ route('me.profile') }}" class="dot-btn dot-btn-ghost">Edit your profile</a>
                @else
                    <a href="{{ route('me.profile') }}" class="dot-btn dot-btn-primary rg-land-go" data-primary-action>Edit your profile</a>
                    <a href="{{ route('jobs.index') }}" class="dot-btn dot-btn-ghost">Browse open roles</a>
                @endif
            </div>
        </div>
    </header>

    <div id="dash-main" tabindex="-1">
    <x-flash />

    <div class="dash-tally" role="group" aria-label="Your profile">
        <span><b>{{ $completeness['done'] }} of {{ $completeness['total'] }}</b> parts filled</span>
        <span><b>{{ $applications->count() }}</b> open {{ Str::plural('application', $applications->count()) }}</span>
        <span><b>{{ $businesses->count() }}</b> {{ Str::plural('workspace', $businesses->count()) }}</span>
    </div>

    <div class="dash-desk r-stack">
        <div>
            <section class="dash-sec" aria-labelledby="h-apps">
                <div class="dash-sec-h"><h2 id="h-apps">Your applications<span>{{ $applications->count() }} open</span></h2></div>
                @forelse ($applications as $application)
                    @php $next = $application->interviews->where('status', 'scheduled')->filter(fn ($i) => $i->starts_at->isFuture())->first(); @endphp
                    @if ($loop->first)<ul class="dash-rows q">@endif
                    <li class="dash-row l">
                        <a class="go" href="{{ route('me.applications.show', $application->id) }}" aria-label="{{ $application->vacancy->title }}, {{ $application->vacancy->team->name }}"></a>
                        <span class="name">{{ $application->vacancy->title }} — {{ $application->vacancy->team->name }}</span>
                        <span class="soft">@if ($next) Interview {{ $next->starts_at->format('D j M, H:i') }} @else Applied {{ $application->created_at->format('j M Y') }} @endif</span>
                        <span class="fig"><span class="dot-badge {{ $application->stage === 'offer' ? 'dot-badge-ok' : 'dot-badge-accent' }}">{{ $application->candidateStageLabel() }}</span></span>
                    </li>
                    @if ($loop->last)</ul>@endif
                @empty
                    <div class="dash-empty">
                        <div>
                            <p class="dot-note">Nothing in progress.</p>
                            <div class="dash-btns"><a href="{{ route('jobs.index') }}" class="dot-btn">Browse open roles</a></div>
                        </div>
                        @include('partials.dash-art', ['variant' => 'empty'])
                    </div>
                @endforelse
                <p class="dash-note">You see Received, In review and the later steps. Internal recruiter notes and scores are never shown to you.</p>
            </section>

            <section class="dash-sec" aria-labelledby="h-ws">
                <div class="dash-sec-h"><h2 id="h-ws">Your workspaces<span>{{ $businesses->count() }}</span></h2></div>
                @forelse ($businesses as $business)
                    @php $roleLabel = $business->user_id === auth()->id() ? 'Owner' : (auth()->user()->teamRole($business)?->name ?? 'Member'); @endphp
                    @if ($loop->first)<ul class="dash-rows n">@endif
                    @if (auth()->user()->current_team_id === $business->id)
                        <li class="dash-row">
                            <a class="go" href="{{ route('dashboard') }}" aria-label="{{ $business->name }}, {{ $roleLabel }}, open"></a>
                            <span class="label">{{ $business->name }}</span><span class="fig soft">{{ $roleLabel }} · open</span>
                        </li>
                    @else
                        <li class="dash-row">
                            <form method="POST" action="{{ route('current-team.update') }}" style="display:contents;">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="team_id" value="{{ $business->id }}">
                                <button type="submit" class="go" style="border:0;background:none;cursor:pointer;" aria-label="{{ $business->name }}, {{ $roleLabel }}, open"></button>
                                <span class="label">{{ $business->name }}</span><span class="fig soft">{{ $roleLabel }} · open</span>
                            </form>
                        </li>
                    @endif
                    @if ($loop->last)</ul>@endif
                @empty
                    <p class="dot-note" style="margin:0;">You are not part of a business on Dot.HR yet. Businesses appear here when InfoDot lists you as one of their people.</p>
                @endforelse
            </section>
        </div>

        <aside class="dash-pane" aria-label="Your profile">
            <div class="dash-sec-h"><h2>Your profile</h2></div>
            <div class="dash-pane-body">
                <h2 class="t">{{ $profile->headline ?: 'No headline yet' }}</h2>
                <div class="dash-track" role="img" aria-label="{{ $completeness['done'] }} of {{ $completeness['total'] }} parts filled"><i style="width: {{ $pct }}%"></i></div>
                <dl class="dash-facts">
                    <div><dt>Availability</dt><dd>{{ \App\Models\ProfessionalProfile::AVAILABILITY[$profile->availability] ?? 'Not set' }}</dd></div>
                    <div><dt>Location</dt><dd>{{ $profile->location ?: 'Not set' }}</dd></div>
                    <div><dt>Skills</dt><dd>{{ $profile->skills->isNotEmpty() ? $profile->skills->pluck('name')->implode(', ') : 'None yet' }}</dd></div>
                    <div><dt>Who sees it</dt><dd>{{ \App\Models\ProfessionalProfile::VISIBILITY[$profile->visibility] }}</dd></div>
                    @if ($profile->visibility === 'public')
                    <div><dt>Share link</dt><dd><a href="{{ route('people.show', $profile->handle) }}">{{ route('people.show', $profile->handle) }}</a></dd></div>
                    @endif
                </dl>
                @if ($completeness['missing'] !== [])
                    <p class="dash-note">Still to add: {{ implode(', ', $completeness['missing']) }}.</p>
                @endif
                <p class="dash-note">Your email address and phone number are never part of your profile. Employers reach you through Dot.HR.</p>
                <div class="dash-btns"><a class="dot-btn" href="{{ route('me.profile') }}">Open profile</a></div>
            </div>
        </aside>
    </div>
    </div>
</div>
</div>
</x-app-layout>
